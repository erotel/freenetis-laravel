<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Repositories\SmsRepository;
use App\Models\Setting;
use App\Models\SmsMessage;

/** Zařadí ručně psanou SMS do fronty brány (9místné číslo → předvolba 420, text bez diakritiky). */
class QueueSmsAction
{
    public function __construct(private SmsRepository $sms)
    {
    }

    public function handle(int $userId, string $receiver, string $text, string $sendDate): SmsMessage
    {
        $receiver = preg_replace('/\s+/', '', $receiver);
        if (strlen($receiver) === 9) {
            $receiver = '420' . $receiver;
        }

        return $this->sms->queue([
            'user_id'   => $userId,
            'send_date' => $sendDate,
            'text'      => iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text),
            'sender'    => Setting::get('sms_sender_number', ''),
            'receiver'  => $receiver,
            'driver'    => (int) Setting::get('sms_driver', 3),
        ]);
    }
}
