<?php

namespace App\Domains\Notifications\Queries;

use App\Domains\Network\Support\IpOwner;
use App\Helpers\MemberType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Data formuláře hromadného upozornění (read model). */
class NotificationMembersQuery
{
    /**
     * Členové kromě bývalých, s kreditem, whitelistem k dnešku, přerušením,
     * aktivním přesměrováním na tuto zprávu a počty e-mailových/telefonních kontaktů.
     */
    public function forMessage(int $messageId): Collection
    {
        $today = now()->toDateString();
        $mid   = $messageId;

        return DB::table('members as m')
            ->select([
                'm.id', 'm.name', 'm.type', 'm.locked',
                'm.notification_by_redirection',
                'm.notification_by_email',
                'm.notification_by_sms',
                DB::raw('(SELECT a.balance FROM accounts a WHERE a.member_id = m.id AND a.account_attribute_id = 221100 LIMIT 1) AS credit_balance'),
                DB::raw('(SELECT 1 FROM members_whitelists mw WHERE mw.member_id = m.id AND mw.since <= ? AND mw.until >= ? LIMIT 1) AS whitelisted'),
                DB::raw('(SELECT 1 FROM membership_interrupts mi WHERE mi.member_id = m.id LIMIT 1) AS interrupted'),
                DB::raw('(SELECT 1 FROM messages_ip_addresses mia JOIN ip_addresses ip ON ip.id = mia.ip_address_id LEFT JOIN ifaces i ON i.id = ip.iface_id LEFT JOIN devices d ON d.id = i.device_id LEFT JOIN users u ON u.id = d.user_id WHERE mia.message_id = ? AND ' . IpOwner::SQL . ' = m.id LIMIT 1) AS has_redirection'),
                DB::raw('(SELECT COUNT(*) FROM contacts c JOIN users_contacts uc ON uc.contact_id = c.id JOIN users u ON u.id = uc.user_id WHERE u.member_id = m.id AND c.type = 20) AS email_contacts_count'),
                DB::raw('(SELECT COUNT(*) FROM contacts c JOIN users_contacts uc ON uc.contact_id = c.id JOIN users u ON u.id = uc.user_id WHERE u.member_id = m.id AND c.type = 21) AS phone_contacts_count'),
            ])
            ->addBinding([$today, $today, $mid], 'select')
            ->whereNotIn('m.type', [MemberType::FORMER, MemberType::FORMER_CUSTOMER])
            ->orderBy('m.name')
            ->get();
    }

    /** Zprávy pro výběr (id => název); $type = jen daný typ (0 = uživatelské zprávy). */
    public function messages(?int $type = null)
    {
        return DB::table('messages')->when($type !== null, fn ($q) => $q->where('type', $type))->orderBy('name')->pluck('name', 'id');
    }

    public function message(int $id): ?object
    {
        return DB::table('messages')->where('id', $id)->first();
    }
}
