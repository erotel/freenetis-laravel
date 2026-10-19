<?php

namespace App\Http\Controllers;

use App\Domains\Notifications\Actions\ResendEmailAction;
use App\Domains\Notifications\Queries\EmailQueueListQuery;
use App\Domains\Notifications\Repositories\EmailQueueRepository;
use App\Domains\Notifications\Support\AttachmentFile;
use App\Models\EmailQueue;
use Illuminate\Http\Request;

/** Správa fronty e-mailů (čekající, odeslané, detail, přílohy); logika v doméně Notifications. */
class EmailQueueController extends Controller
{
    const ACL_SECTION = 'Email_queues_Controller';
    const ACL_KEY     = 'email_queue';

    public function __construct(private EmailQueueRepository $queue, private EmailQueueListQuery $list) {}

    private function canView(): bool   { return $this->aclCheck('view_all',   self::ACL_SECTION, self::ACL_KEY); }
    private function canSend(): bool   { return $this->aclCheck('new_all',    self::ACL_SECTION, self::ACL_KEY); }
    private function canDelete(): bool { return $this->aclCheck('delete_all', self::ACL_SECTION, self::ACL_KEY); }

    private function filters(Request $request): array
    {
        return ['from' => $request->input('from'), 'to' => $request->input('to'), 'subject' => $request->input('subject')];
    }

    private function listData(Request $request, int $state): array
    {
        $all = $request->boolean('all');
        return [
            'emails'     => $this->list->paginate($state, $all, $this->filters($request), 50),
            'canDelete'  => $this->canDelete(),
            'filterFrom' => $request->input('from', ''),
            'filterTo'   => $request->input('to', ''),
            'filterSubj' => $request->input('subject', ''),
            'loadAll'    => $all,
        ];
    }

    public function unsent(Request $request)
    {
        abort_unless($this->canView(), 403);
        return view('email_queues.unsent', $this->listData($request, EmailQueue::STATE_NEW) + ['canSend' => $this->canSend()]);
    }

    public function sent(Request $request)
    {
        abort_unless($this->canView(), 403);
        return view('email_queues.sent', $this->listData($request, EmailQueue::STATE_SENT));
    }

    public function show(int $id)
    {
        abort_unless($this->canView(), 403);
        $email = $this->queue->findWithAttachmentsOrFail($id);

        return view('email_queues.show', [
            'email'     => $email,
            'bodyHtml'  => $this->resolveInlineCids($email),
            'canSend'   => $this->canSend(),
            'canDelete' => $this->canDelete(),
        ]);
    }

    /**
     * `cid:<name>` inline příloh (QR platba) prohlížeč nevykreslí — v náhledu
     * je přepíšeme na URL přílohy, ať admin vidí to, co dostane příjemce.
     */
    private function resolveInlineCids(EmailQueue $email): string
    {
        $body = (string) $email->body;
        foreach ($email->attachments as $att) {
            if (!empty($att->inline) && (string) $att->name !== '') {
                $body = str_replace('cid:' . $att->name, route('email_queues.attachment', [$email->id, $att->id]), $body);
            }
        }
        return $body;
    }

    public function downloadAttachment(int $id, int $attachmentId)
    {
        abort_unless($this->canView(), 403);

        $att  = $this->queue->findOrFail($id)->attachments()->where('id', $attachmentId)->firstOrFail();
        $real = AttachmentFile::resolve((string) $att->path);
        if ($real === null) {
            abort(404, 'Soubor přílohy nenalezen.');
        }
        if ($real === false) {
            abort(403, 'Příloha mimo povolený adresář.');
        }

        return response()->download($real, $att->name ?: basename($real), ['Content-Type' => $att->mime ?: 'application/octet-stream']);
    }

    public function resend(int $id, ResendEmailAction $resend)
    {
        abort_unless($this->canSend(), 403);

        return $resend->handle($this->queue->findWithAttachmentsOrFail($id))
            ? redirect()->route('email_queues.unsent')->with('success', "E-mail #{$id} byl úspěšně odeslán.")
            : redirect()->route('email_queues.unsent')->with('error', "E-mail #{$id} se nepodařilo odeslat. Zkontrolujte nastavení SMTP.");
    }

    public function destroy(int $id)
    {
        abort_unless($this->canDelete(), 403);

        return $this->queue->deletePending($this->queue->findOrFail($id))
            ? redirect()->route('email_queues.unsent')->with('success', "E-mail #{$id} byl smazán.")
            : redirect()->route('email_queues.unsent')->with('error', 'Lze smazat pouze čekající e-maily.');
    }

    public function destroyUnsent()
    {
        abort_unless($this->canDelete(), 403);
        $count = $this->queue->deleteAllPending();
        return redirect()->route('email_queues.unsent')->with('success', "Smazáno {$count} čekajících e-mailů.");
    }

    public function destroySent(Request $request)
    {
        abort_unless($this->canDelete(), 403);
        $count = $this->queue->deleteSent($this->filters($request));
        return redirect()->route('email_queues.sent')->with('success', "Smazáno {$count} odeslaných e-mailů.");
    }
}
