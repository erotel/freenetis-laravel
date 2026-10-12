<?php

namespace App\Domains\Notifications\Repositories;

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

    /** Zařadí e-mail do fronty (odešle ho cron fronty). */
    public function enqueue(string $from, string $to, string $subject, string $body): void
    {
        DB::table('email_queues')->insert([
            'from'    => $from,
            'to'      => $to,
            'subject' => $subject,
            'body'    => $body,
            'state'   => 0,
        ]);
    }
}
