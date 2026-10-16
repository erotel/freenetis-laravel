<?php

namespace App\Domains\Notifications\Actions;

use App\Models\Message;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Šablona zprávy (messages) e-mailem na VŠECHNY e-mailové kontakty všech
 * uživatelů člena. Placeholdery {member_name}, {member_id}, {balance}… doplní
 * Message::buildPlaceholders; $vars volajícího mají přednost. Šablona bez
 * textu e-mailu nebo člen bez e-mailu = nic.
 */
class SendTemplateToMemberAction
{
    public function handle(int $messageId, int $memberId, array $vars = []): void
    {
        $message = DB::table('messages')->where('id', $messageId)->first();
        if (!$message || empty($message->email_text)) {
            return;
        }

        $emails = DB::table('contacts AS c')
            ->join('users_contacts AS uc', 'uc.contact_id', '=', 'c.id')
            ->join('users AS u', 'u.id', '=', 'uc.user_id')
            ->where('u.member_id', $memberId)
            ->where('c.type', 20) // CONTACT_EMAIL
            ->pluck('c.value');

        if ($emails->isEmpty()) {
            return;
        }

        $from    = Setting::get('email_default_email', 'noreply@freenetis.org');
        $prefix  = Setting::get('email_subject_prefix', '');
        $subject = ($prefix ? $prefix . ' :: ' : '') . $message->name;
        $body    = Message::substitute($message->email_text, Message::buildPlaceholders($memberId, $vars));

        foreach ($emails as $to) {
            DB::table('email_queues')->insert([
                'from'    => $from,
                'to'      => $to,
                'subject' => $subject,
                'body'    => $body,
                'state'   => 0,
            ]);
        }
    }
}
