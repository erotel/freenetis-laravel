<?php

namespace App\Domains\Notifications\Actions;

use App\Models\EmailQueue;
use App\Services\EmailSenderService;

/** Znovu odešle e-mail z fronty hned (ne přes cron): výsledek → stav odesláno / chyba. */
class ResendEmailAction
{
    public function __construct(private EmailSenderService $sender)
    {
    }

    public function handle(EmailQueue $queued): bool
    {
        $queued->update(['state' => EmailQueue::STATE_NEW]);
        $ok = $this->sender->sendOne($queued);
        $queued->update(['state' => $ok ? EmailQueue::STATE_SENT : EmailQueue::STATE_FAILED, 'access_time' => now()]);
        return $ok;
    }
}
