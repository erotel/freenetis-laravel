<?php

namespace App\Domains\Notifications\Repositories;

use App\Models\EmailQueue;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Šablony zpráv (messages) a fronta odchozích e-mailů (email_queues).
 */
class EmailQueueRepository
{
    /** Šablona zprávy, jen pokud má vyplněný text e-mailu. */
    public function emailTemplate(int $messageId): ?Message
    {
        $message = Message::where('id', $messageId)->first();

        return ($message && $message->email_text) ? $message : null;
    }

    /** Zařadí e-mail do fronty (odešle ho cron fronty). Vrací id ve frontě. */
    public function enqueue(string $from, string $to, string $subject, string $body): int
    {
        return (int) DB::table('email_queues')->insertGetId([
            'from'    => $from,
            'to'      => $to,
            'subject' => $subject,
            'body'    => $body,
            'state'   => 0,
        ]);
    }

    /** Příloha k e-mailu ve frontě (soubor na disku). */
    public function attach(int $emailQueueId, string $path, string $name, string $mime): void
    {
        DB::table('email_queue_attachments')->insert([
            'email_queue_id' => $emailQueueId,
            'path'           => $path,
            'name'           => $name,
            'mime'           => $mime,
            'created_at'     => now(),
        ]);
    }

    public function findOrFail(int $id): EmailQueue
    {
        return EmailQueue::findOrFail($id);
    }

    public function findWithAttachmentsOrFail(int $id): EmailQueue
    {
        return EmailQueue::with('attachments')->findOrFail($id);
    }

    /** Smaže e-mail jen když ještě čeká. @return bool false = už odeslaný / chybný */
    public function deletePending(EmailQueue $email): bool
    {
        if ($email->state !== EmailQueue::STATE_NEW) {
            return false;
        }
        $email->delete();
        return true;
    }

    /** @return int počet smazaných čekajících */
    public function deleteAllPending(): int
    {
        $count = EmailQueue::where('state', EmailQueue::STATE_NEW)->count();
        EmailQueue::where('state', EmailQueue::STATE_NEW)->delete();
        return $count;
    }

    /** @return int počet smazaných odeslaných (podle filtrů) */
    public function deleteSent(array $filters): int
    {
        $query = EmailQueue::where('state', EmailQueue::STATE_SENT);
        \App\Domains\Notifications\Queries\EmailQueueListQuery::applyFilters($query, $filters);
        $count = $query->count();
        $query->delete();
        return $count;
    }
}
