<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Setting;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;

abstract class Controller
{
    protected function aclCheck(string $action, string $section, string $value): bool
    {
        return app(AclService::class)->hasAccess(auth()->id() ?? 0, $action, $section, $value);
    }

    /**
     * Send a message template email to all email contacts of a member.
     * Substitutes {key} placeholders from $vars array.
     */
    protected function sendMessageToMember(int $messageId, int $memberId, array $vars = []): void
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

        // Auto-substitute standard placeholders ({member_name}, {member_id},
        // {leaving_date}, {entrance_date}, {balance}); caller's $vars win.
        $allVars = Message::buildPlaceholders($memberId, $vars);
        $body    = Message::substitute($message->email_text, $allVars);

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
