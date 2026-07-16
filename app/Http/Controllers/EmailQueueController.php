<?php

namespace App\Http\Controllers;

use App\Models\EmailQueue;
use App\Services\EmailSenderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmailQueueController extends Controller
{
    const ACL_SECTION = 'Email_queues_Controller';
    const ACL_KEY     = 'email_queue';

    private function canView(): bool   { return $this->aclCheck('view_all',   self::ACL_SECTION, self::ACL_KEY); }
    private function canSend(): bool   { return $this->aclCheck('new_all',    self::ACL_SECTION, self::ACL_KEY); }
    private function canDelete(): bool { return $this->aclCheck('delete_all', self::ACL_SECTION, self::ACL_KEY); }

    // ── Čekající (state = 0) ──────────────────────────────────────────────────

    public function unsent(Request $request)
    {
        abort_unless($this->canView(), 403);

        $loadAll = $request->boolean('all');
        $query = $this->stateQuery(EmailQueue::STATE_NEW, $loadAll);
        $this->applyFilters($query, $request);

        $emails = $query->orderByDesc('email_queues.id')->paginate(50)->withQueryString();

        return view('email_queues.unsent', [
            'emails'      => $emails,
            'canSend'     => $this->canSend(),
            'canDelete'   => $this->canDelete(),
            'filterFrom'  => $request->input('from', ''),
            'filterTo'    => $request->input('to', ''),
            'filterSubj'  => $request->input('subject', ''),
            'loadAll'     => $loadAll,
        ]);
    }

    // ── Odeslané (state = 1) ──────────────────────────────────────────────────

    public function sent(Request $request)
    {
        abort_unless($this->canView(), 403);

        $loadAll = $request->boolean('all');
        $query = $this->stateQuery(EmailQueue::STATE_SENT, $loadAll);
        $this->applyFilters($query, $request);

        $emails = $query->orderByDesc('email_queues.id')->paginate(50)->withQueryString();

        return view('email_queues.sent', [
            'emails'     => $emails,
            'canDelete'  => $this->canDelete(),
            'filterFrom' => $request->input('from', ''),
            'filterTo'   => $request->input('to', ''),
            'filterSubj' => $request->input('subject', ''),
            'loadAll'    => $loadAll,
        ]);
    }

    // ── Detail e-mailu ────────────────────────────────────────────────────────

    public function show(int $id)
    {
        abort_unless($this->canView(), 403);

        $email = EmailQueue::with('attachments')->findOrFail($id);

        return view('email_queues.show', [
            'email'     => $email,
            'bodyHtml'  => $this->resolveInlineCids($email),
            'canSend'   => $this->canSend(),
            'canDelete' => $this->canDelete(),
        ]);
    }

    /**
     * V náhledu (prohlížeč) se `cid:<name>` odkazy inline příloh (např. QR platba)
     * nevykreslí — cid rozumí jen e-mailový klient. Přepíšeme je proto na URL
     * endpointu, který přílohu naservíruje, ať admin v náhledu vidí to samé,
     * co dostane příjemce v e-mailu.
     */
    private function resolveInlineCids(EmailQueue $email): string
    {
        $body = (string) $email->body;
        foreach ($email->attachments as $att) {
            if (empty($att->inline) || (string) $att->name === '') {
                continue;
            }
            $url  = route('email_queues.attachment', [$email->id, $att->id]);
            $body = str_replace('cid:' . $att->name, $url, $body);
        }
        return $body;
    }

    // ── Stažení přílohy ───────────────────────────────────────────────────────

    public function downloadAttachment(int $id, int $attachmentId)
    {
        abort_unless($this->canView(), 403);

        $email = EmailQueue::findOrFail($id);
        $att   = $email->attachments()->where('id', $attachmentId)->firstOrFail();

        // Legacy ukládá absolutní cestu (např. /usr/share/freenetis/data/invoices/...);
        // download je povolen jen z whitelistovaných kořenů, aby attachment endpoint
        // nešel zneužít na čtení libovolného souboru na serveru.
        $real = realpath($att->path);
        if ($real === false) {
            abort(404, 'Soubor přílohy nenalezen.');
        }
        $allowedRoots = array_filter([
            realpath('/usr/share/freenetis/data'),
            realpath(storage_path('app')),
            realpath(base_path('storage/app')),
        ]);
        $ok = false;
        foreach ($allowedRoots as $root) {
            if ($root && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) { $ok = true; break; }
        }
        if (!$ok) {
            abort(403, 'Příloha mimo povolený adresář.');
        }

        return response()->download($real, $att->name ?: basename($real), [
            'Content-Type' => $att->mime ?: 'application/octet-stream',
        ]);
    }

    // ── Sestavení query s/bez limitu 200 ──────────────────────────────────────

    private function stateQuery(int $state, bool $loadAll): \Illuminate\Database\Query\Builder
    {
        if ($loadAll) {
            return DB::table('email_queues')->where('state', $state);
        }
        $last200 = DB::table('email_queues')->select('id')
            ->where('state', $state)
            ->orderByDesc('id')->limit(200);
        return DB::table('email_queues')
            ->joinSub($last200, 'last200', fn($j) => $j->on('email_queues.id', '=', 'last200.id'))
            ->select('email_queues.*')
            ->where('email_queues.state', $state);
    }

    // ── Znovu odeslat ─────────────────────────────────────────────────────────

    public function resend(int $id, EmailSenderService $sender)
    {
        abort_unless($this->canSend(), 403);

        $queued = EmailQueue::with('attachments')->findOrFail($id);

        // Reset stavu na NEW, aby byl konzistentní před odesláním
        $queued->update(['state' => EmailQueue::STATE_NEW]);

        $ok = $sender->sendOne($queued);

        $queued->update([
            'state'       => $ok ? EmailQueue::STATE_SENT : EmailQueue::STATE_FAILED,
            'access_time' => now(),
        ]);

        if ($ok) {
            return redirect()->route('email_queues.unsent')
                ->with('success', "E-mail #{$id} byl úspěšně odeslán.");
        }

        return redirect()->route('email_queues.unsent')
            ->with('error', "E-mail #{$id} se nepodařilo odeslat. Zkontrolujte nastavení SMTP.");
    }

    // ── Smazat jeden (jen neodeslaný) ────────────────────────────────────────

    public function destroy(int $id)
    {
        abort_unless($this->canDelete(), 403);

        $email = EmailQueue::findOrFail($id);

        if ($email->state !== EmailQueue::STATE_NEW) {
            return redirect()->route('email_queues.unsent')
                ->with('error', 'Lze smazat pouze čekající e-maily.');
        }

        $email->delete();

        return redirect()->route('email_queues.unsent')
            ->with('success', "E-mail #{$id} byl smazán.");
    }

    // ── Smazat všechny čekající ───────────────────────────────────────────────

    public function destroyUnsent()
    {
        abort_unless($this->canDelete(), 403);

        $count = EmailQueue::where('state', EmailQueue::STATE_NEW)->count();
        EmailQueue::where('state', EmailQueue::STATE_NEW)->delete();

        return redirect()->route('email_queues.unsent')
            ->with('success', "Smazáno {$count} čekajících e-mailů.");
    }

    // ── Smazat odeslané (s filtrem) ───────────────────────────────────────────

    public function destroySent(Request $request)
    {
        abort_unless($this->canDelete(), 403);

        $query = EmailQueue::where('state', EmailQueue::STATE_SENT);

        if ($f = $request->input('from'))    { $query->where('from', 'like', "%{$f}%"); }
        if ($f = $request->input('to'))      { $query->where('to',   'like', "%{$f}%"); }
        if ($f = $request->input('subject')) { $query->where('subject', 'like', "%{$f}%"); }

        $count = $query->count();
        $query->delete();

        return redirect()->route('email_queues.sent')
            ->with('success', "Smazáno {$count} odeslaných e-mailů.");
    }

    // ── Pomocné ───────────────────────────────────────────────────────────────

    private function applyFilters(\Illuminate\Database\Query\Builder $query, Request $request): void
    {
        if ($f = $request->input('from'))    { $query->where('from', 'like', "%{$f}%"); }
        if ($f = $request->input('to'))      { $query->where('to',   'like', "%{$f}%"); }
        if ($f = $request->input('subject')) { $query->where('subject', 'like', "%{$f}%"); }
    }
}
