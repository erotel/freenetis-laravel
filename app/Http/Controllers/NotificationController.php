<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    const ACTIVATE   = 1;
    const KEEP       = 2;
    const DEACTIVATE = 3;

    // contact type constants (same as Kohana Contact_Model)
    const CONTACT_EMAIL = 20;
    const CONTACT_PHONE = 21;

    private function can(string $action = 'new_all'): bool
    {
        return $this->aclCheck($action, 'Notifications_Controller', 'member');
    }

    /**
     * Show notification form for a member.
     */
    public function member(int $id)
    {
        abort_unless($this->can(), 403);

        $member = DB::table('members')->where('id', $id)->first();
        abort_if(!$member, 404);

        // Messages of type 0 (USER_MESSAGE — general user/member messages)
        $messages = DB::table('messages')
            ->where('type', 0)
            ->orderBy('name')
            ->pluck('name', 'id');

        return view('notifications.member', [
            'member'   => $member,
            'messages' => $messages,
            'ACTIVATE'   => self::ACTIVATE,
            'KEEP'       => self::KEEP,
            'DEACTIVATE' => self::DEACTIVATE,
        ]);
    }

    /**
     * Process notification form for a member.
     */
    public function notify(Request $request, int $id)
    {
        abort_unless($this->can(), 403);

        $member = DB::table('members')->where('id', $id)->first();
        abort_if(!$member, 404);

        $messageId   = (int) $request->input('message_id', 0);
        $comment     = trim((string) $request->input('comment', ''));
        $redirection = (int) $request->input('redirection', self::KEEP);
        $email       = (int) $request->input('email', self::KEEP);
        $sms         = (int) $request->input('sms', self::KEEP);

        $message = DB::table('messages')->where('id', $messageId)->where('type', 0)->first();
        if (!$message) {
            return back()->withInput()->withErrors(['message_id' => 'Vyberte zprávu.']);
        }

        $userId = Auth::id();

        $stats = [
            'redirections_activated'   => 0,
            'redirections_deactivated' => 0,
            'emails_sent'              => 0,
            'smss_sent'                => 0,
        ];

        // ── Redirection ───────────────────────────────────────────────────────
        if ($redirection !== self::KEEP) {
            $ipRows = DB::table('ip_addresses AS ia')
                ->join('ifaces AS i', 'i.id', '=', 'ia.iface_id')
                ->join('devices AS d', 'd.id', '=', 'i.device_id')
                ->join('users AS u', 'u.id', '=', 'd.user_id')
                ->where('u.member_id', $id)
                ->select('ia.id AS ip_address_id')
                ->get();

            if ($redirection === self::ACTIVATE) {
                $now = now()->format('Y-m-d H:i:s');
                foreach ($ipRows as $ip) {
                    DB::statement('
                        INSERT INTO messages_ip_addresses
                            (message_id, ip_address_id, user_id, comment, datetime)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            user_id = VALUES(user_id),
                            comment = VALUES(comment),
                            datetime = VALUES(datetime)
                    ', [$messageId, $ip->ip_address_id, $userId, $comment ?: null, $now]);
                    $stats['redirections_activated']++;
                }
            } elseif ($redirection === self::DEACTIVATE) {
                foreach ($ipRows as $ip) {
                    $deleted = DB::table('messages_ip_addresses')
                        ->where('message_id', $messageId)
                        ->where('ip_address_id', $ip->ip_address_id)
                        ->delete();
                    $stats['redirections_deactivated'] += $deleted;
                }
            }
        }

        // ── E-mail ────────────────────────────────────────────────────────────
        if ($email === self::ACTIVATE && $message->email_text) {
            $emails = DB::table('contacts AS c')
                ->join('users_contacts AS uc', 'uc.contact_id', '=', 'c.id')
                ->join('users AS u', 'u.id', '=', 'uc.user_id')
                ->where('u.member_id', $id)
                ->where('c.type', self::CONTACT_EMAIL)
                ->pluck('c.value');

            $from    = Setting::get('email_default_email', 'noreply@freenetis.org');
            $prefix  = Setting::get('email_subject_prefix', '');
            $subject = ($prefix ? $prefix . ' :: ' : '') . $message->name;

            foreach ($emails as $to) {
                DB::table('email_queues')->insert([
                    'from'    => $from,
                    'to'      => $to,
                    'subject' => $subject,
                    'body'    => $message->email_text,
                    'state'   => 0,
                ]);
                $stats['emails_sent']++;
            }
        }

        // ── SMS ───────────────────────────────────────────────────────────────
        if ($sms === self::ACTIVATE && $message->sms_text) {
            $smsEnabled = Setting::get('sms_enabled', '0');
            $smsDriver  = Setting::get('sms_driver', '');
            $smsSender  = Setting::get('sms_sender_number', '');

            if ($smsEnabled && $smsDriver && $smsSender) {
                $phones = DB::table('contacts AS c')
                    ->join('users_contacts AS uc', 'uc.contact_id', '=', 'c.id')
                    ->join('users AS u', 'u.id', '=', 'uc.user_id')
                    ->where('u.member_id', $id)
                    ->where('c.type', self::CONTACT_PHONE)
                    ->pluck('c.value');

                $now = now()->format('Y-m-d H:i:s');

                DB::transaction(function () use ($phones, $message, $userId, $smsDriver, $smsSender, $now, &$stats) {
                    foreach ($phones as $phone) {
                        DB::table('sms_messages')->insert([
                            'user_id'        => $userId,
                            'sms_message_id' => null,
                            'stamp'          => $now,
                            'send_date'      => $now,
                            'text'           => $message->sms_text,
                            'sender'         => $smsSender,
                            'receiver'       => $phone,
                            'driver'         => (int) $smsDriver,
                            'type'           => 1,    // SENT
                            'state'          => 1,    // SENT_UNSENT
                        ]);
                        $stats['smss_sent']++;
                    }
                });
            }
        }

        // ── Build flash message ───────────────────────────────────────────────
        $parts = [];
        if ($stats['redirections_activated'])   $parts[] = "Přesměrování aktivováno pro {$stats['redirections_activated']} IP.";
        if ($stats['redirections_deactivated']) $parts[] = "Přesměrování deaktivováno pro {$stats['redirections_deactivated']} IP.";
        if ($stats['emails_sent'])              $parts[] = "Odesláno {$stats['emails_sent']} e-mailů.";
        if ($stats['smss_sent'])               $parts[] = "Odesláno {$stats['smss_sent']} SMS.";
        if (empty($parts))                      $parts[] = 'Žádná akce nebyla provedena.';

        return redirect()
            ->route('members.show', $id)
            ->with('success', implode(' ', $parts));
    }
}
