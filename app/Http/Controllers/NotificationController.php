<?php

namespace App\Http\Controllers;

use App\Helpers\MemberType;
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
     * Step 1: select message for bulk member notification.
     */
    public function membersSelect()
    {
        abort_unless($this->can(), 403);

        $messages = DB::table('messages')->orderBy('name')->pluck('name', 'id');

        return view('notifications.members_select', [
            'messages' => $messages,
        ]);
    }

    /**
     * Show bulk notification form for all members.
     */
    public function members(int $messageId)
    {
        abort_unless($this->can(), 403);

        $message = DB::table('messages')->where('id', $messageId)->first();
        abort_if(!$message, 404);

        $today = now()->toDateString();
        $mid   = (int) $messageId;

        $members = DB::table('members as m')
            ->select([
                'm.id', 'm.name', 'm.type', 'm.locked',
                'm.notification_by_redirection',
                'm.notification_by_email',
                'm.notification_by_sms',
                DB::raw('(SELECT a.balance FROM accounts a WHERE a.member_id = m.id AND a.account_attribute_id = 221100 LIMIT 1) AS credit_balance'),
                DB::raw('(SELECT 1 FROM members_whitelists mw WHERE mw.member_id = m.id AND mw.since <= ? AND mw.until >= ? LIMIT 1) AS whitelisted'),
                DB::raw('(SELECT 1 FROM membership_interrupts mi WHERE mi.member_id = m.id LIMIT 1) AS interrupted'),
                DB::raw('(SELECT 1 FROM messages_ip_addresses mia JOIN ip_addresses ia ON ia.id = mia.ip_address_id JOIN ifaces i ON i.id = ia.iface_id JOIN devices d ON d.id = i.device_id JOIN users u ON u.id = d.user_id WHERE mia.message_id = ? AND u.member_id = m.id LIMIT 1) AS has_redirection'),
                DB::raw('(SELECT COUNT(*) FROM contacts c JOIN users_contacts uc ON uc.contact_id = c.id JOIN users u ON u.id = uc.user_id WHERE u.member_id = m.id AND c.type = 20) AS email_contacts_count'),
                DB::raw('(SELECT COUNT(*) FROM contacts c JOIN users_contacts uc ON uc.contact_id = c.id JOIN users u ON u.id = uc.user_id WHERE u.member_id = m.id AND c.type = 21) AS phone_contacts_count'),
            ])
            ->addBinding([$today, $today, $mid], 'select')
            ->whereNotIn('m.type', [MemberType::FORMER, MemberType::FORMER_CUSTOMER])
            ->orderBy('m.name')
            ->get();

        return view('notifications.members', [
            'message'    => $message,
            'members'    => $members,
            'ACTIVATE'   => self::ACTIVATE,
            'KEEP'       => self::KEEP,
            'DEACTIVATE' => self::DEACTIVATE,
        ]);
    }

    /**
     * Process bulk notification for all members.
     */
    public function membersNotify(Request $request, int $messageId)
    {
        abort_unless($this->can(), 403);

        $message = DB::table('messages')->where('id', $messageId)->first();
        abort_if(!$message, 404);

        $comment     = trim((string) $request->input('comment', ''));
        $userId      = Auth::id();
        $now         = now()->format('Y-m-d H:i:s');

        // Form posílá akce v jediném JSON inputu (`bulk_payload`), aby se vyhnul
        // PHP limitu `max_input_vars=1000`. Pro 2000 členů by N×3 hidden inputů
        // byl backend dostal jen prvních ~333 (chybný stav 264 → 120 hlášení).
        $payload      = json_decode((string) $request->input('bulk_payload', '{}'), true) ?: [];
        $redirections = $payload['redirection'] ?? [];
        $emails       = array_fill_keys($payload['email'] ?? [], self::ACTIVATE);
        $smss         = array_fill_keys($payload['sms']   ?? [], self::ACTIVATE);

        $stats = ['redir_activated' => 0, 'redir_deactivated' => 0, 'emails_sent' => 0, 'smss_sent' => 0];

        $allMemberIds = array_unique(array_map('intval', array_merge(
            array_keys($redirections), array_keys($emails), array_keys($smss)
        )));

        $whitelistedIds = DB::table('members_whitelists')
            ->whereIn('member_id', $allMemberIds)
            ->where('since', '<=', now()->toDateString())
            ->where('until', '>=', now()->toDateString())
            ->pluck('member_id')
            ->flip()
            ->toArray();

        // ── Přesměrování ──────────────────────────────────────────────────────
        // Drobná, rychlá modifikace pár stovek řádků v messages_ip_addresses —
        // necháno v jediné transakci, ať je atomické vůči souběžnému cronu.
        DB::transaction(function () use (
            $messageId, $message, $comment, $userId, $now,
            $redirections, $whitelistedIds, &$stats
        ) {
            foreach ($redirections as $memberId => $action) {
                $memberId = (int) $memberId;
                $action   = (int) $action;
                if ($action === self::KEEP) continue;

                $ipIds = DB::table('ip_addresses as ia')
                    ->join('ifaces as i', 'i.id', '=', 'ia.iface_id')
                    ->join('devices as d', 'd.id', '=', 'i.device_id')
                    ->join('users as u', 'u.id', '=', 'd.user_id')
                    ->where('u.member_id', $memberId)
                    ->pluck('ia.id');

                if ($ipIds->isEmpty()) continue;

                if ($action === self::DEACTIVATE) {
                    $stats['redir_deactivated'] += DB::table('messages_ip_addresses')
                        ->where('message_id', $messageId)
                        ->whereIn('ip_address_id', $ipIds)
                        ->delete();
                } elseif ($action === self::ACTIVATE) {
                    if (!$message->ignore_whitelist && isset($whitelistedIds[$memberId])) continue;

                    DB::table('messages_ip_addresses')
                        ->where('message_id', $messageId)
                        ->whereIn('ip_address_id', $ipIds)
                        ->delete();

                    $rows = $ipIds->map(fn ($ipId) => [
                        'message_id'    => $messageId,
                        'ip_address_id' => $ipId,
                        'user_id'       => $userId,
                        'comment'       => $comment ?: null,
                        'datetime'      => $now,
                    ])->all();
                    DB::table('messages_ip_addresses')->insertOrIgnore($rows);
                    $stats['redir_activated'] += count($rows);
                }
            }
        });

        // ── E-mail (chunkovaně, mimo transakci — 2000 řádků by jinak drželo
        // dlouhý lock + paměť. Když cokoliv selže v půlce, předchozí dávky
        // už jsou bezpečně queue-d a `email:send-queue` je pošle.) ──────────
        if ($message->email_text) {
            $from    = Setting::get('email_default_email', 'noreply@freenetis.org');
            $prefix  = Setting::get('email_subject_prefix', '');
            $subject = ($prefix ? $prefix . ' :: ' : '') . $message->name;
            $body    = str_replace('{comment}', $comment, $message->email_text);

            $emailMemberIds = array_keys(array_filter(
                $emails, fn ($a) => (int) $a === self::ACTIVATE
            ));

            if ($emailMemberIds) {
                $rowsBuffer = [];
                $contacts   = DB::table('contacts as c')
                    ->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')
                    ->join('users as u', 'u.id', '=', 'uc.user_id')
                    ->whereIn('u.member_id', $emailMemberIds)
                    ->where('c.type', self::CONTACT_EMAIL)
                    ->pluck('c.value');

                foreach ($contacts as $to) {
                    $rowsBuffer[] = [
                        'from'        => $from,
                        'to'          => $to,
                        'subject'     => $subject,
                        'body'        => $body,
                        'state'       => 0,
                        'access_time' => $now,
                    ];
                    if (count($rowsBuffer) >= 500) {
                        DB::table('email_queues')->insert($rowsBuffer);
                        $stats['emails_sent'] += count($rowsBuffer);
                        $rowsBuffer = [];
                    }
                }
                if ($rowsBuffer) {
                    DB::table('email_queues')->insert($rowsBuffer);
                    $stats['emails_sent'] += count($rowsBuffer);
                }
            }
        }

        // ── SMS (stejný pattern jako e-mail) ──────────────────────────────────
        if ($message->sms_text) {
            $smsEnabled = Setting::get('sms_enabled', '0');
            $smsDriver  = Setting::get('sms_driver', '');
            $smsSender  = Setting::get('sms_sender_number', '');

            if ($smsEnabled && $smsDriver && $smsSender) {
                $text = str_replace('{comment}', $comment, $message->sms_text);

                $smsMemberIds = array_keys(array_filter(
                    $smss, fn ($a) => (int) $a === self::ACTIVATE
                ));

                if ($smsMemberIds) {
                    $rowsBuffer = [];
                    $phones     = DB::table('contacts as c')
                        ->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')
                        ->join('users as u', 'u.id', '=', 'uc.user_id')
                        ->whereIn('u.member_id', $smsMemberIds)
                        ->where('c.type', self::CONTACT_PHONE)
                        ->pluck('c.value');

                    foreach ($phones as $phone) {
                        $rowsBuffer[] = [
                            'user_id'        => $userId,
                            'sms_message_id' => null,
                            'stamp'          => $now,
                            'send_date'      => $now,
                            'text'           => $text,
                            'sender'         => $smsSender,
                            'receiver'       => $phone,
                            'driver'         => (int) $smsDriver,
                            'type'           => 1,
                            'state'          => 1,
                        ];
                        if (count($rowsBuffer) >= 500) {
                            DB::table('sms_messages')->insert($rowsBuffer);
                            $stats['smss_sent'] += count($rowsBuffer);
                            $rowsBuffer = [];
                        }
                    }
                    if ($rowsBuffer) {
                        DB::table('sms_messages')->insert($rowsBuffer);
                        $stats['smss_sent'] += count($rowsBuffer);
                    }
                }
            }
        }

        $parts = [];
        if ($stats['redir_activated'])   $parts[] = "Přesměrování aktivováno pro {$stats['redir_activated']} IP.";
        if ($stats['redir_deactivated']) $parts[] = "Přesměrování deaktivováno pro {$stats['redir_deactivated']} IP.";
        if ($stats['emails_sent'])       $parts[] = "Odesláno {$stats['emails_sent']} e-mailů.";
        if ($stats['smss_sent'])         $parts[] = "Odesláno {$stats['smss_sent']} SMS.";
        if (empty($parts))               $parts[] = 'Žádná akce nebyla provedena.';

        return redirect()
            ->route('notifications.members', $messageId)
            ->with('success', implode(' ', $parts));
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

        $message = DB::table('messages')->where('id', $messageId)->first();
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
