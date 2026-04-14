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

        $query = DB::table('email_queues')
            ->where('state', EmailQueue::STATE_NEW);

        $this->applyFilters($query, $request);

        $emails = $query->orderByDesc('id')->paginate(50)->withQueryString();

        return view('email_queues.unsent', [
            'emails'      => $emails,
            'canSend'     => $this->canSend(),
            'canDelete'   => $this->canDelete(),
            'filterFrom'  => $request->input('from', ''),
            'filterTo'    => $request->input('to', ''),
            'filterSubj'  => $request->input('subject', ''),
        ]);
    }

    // ── Odeslané (state = 1) ──────────────────────────────────────────────────

    public function sent(Request $request)
    {
        abort_unless($this->canView(), 403);

        $query = DB::table('email_queues')
            ->where('state', EmailQueue::STATE_SENT);

        $this->applyFilters($query, $request);

        $emails = $query->orderByDesc('id')->paginate(50)->withQueryString();

        return view('email_queues.sent', [
            'emails'     => $emails,
            'canDelete'  => $this->canDelete(),
            'filterFrom' => $request->input('from', ''),
            'filterTo'   => $request->input('to', ''),
            'filterSubj' => $request->input('subject', ''),
        ]);
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
