<?php

namespace App\Domains\Notifications\Queries;

use App\Models\Account;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Data stránky přesměrovaného zákazníka (veřejná, podle IP klienta):
 * nejnovější aktivní přesměrování IP, zpráva pro neevidované zařízení,
 * údaje člena (jméno, saldo, zaplaceno do, VS).
 */
class RedirectionPageQuery
{
    /** Nejnovější aktivní přesměrování IP (řádek existuje jen po dobu přesměrování). */
    public function activeFor(string $ip): ?object
    {
        return DB::table('messages_ip_addresses as mia')
            ->join('messages as m', 'm.id', '=', 'mia.message_id')
            ->join('ip_addresses as ip', 'ip.id', '=', 'mia.ip_address_id')
            ->leftJoin('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->leftJoin('devices as d', 'd.id', '=', 'i.device_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->where('ip.ip_address', $ip)
            ->orderByDesc('mia.datetime')
            ->limit(1)
            ->selectRaw('
                m.id        as message_id,
                m.name      as message_name,
                m.text      as message_text,
                m.type      as message_type,
                mia.comment as comment,
                mia.datetime as redirected_at,
                ip.ip_address,
                COALESCE(ip.member_id, u.member_id) as member_id
            ')
            ->first();
    }

    public function isKnownIp(string $ip): bool
    {
        return DB::table('ip_addresses')->where('ip_address', $ip)->exists();
    }

    public function unknownDeviceMessage(): ?Message
    {
        return Message::where('type', Message::UNKNOWN_DEVICE_MESSAGE)->first();
    }

    /** @return array{member: ?object, balance: ?float, expiration_date: mixed, variable_symbol: ?string} */
    public function member(int $memberId): array
    {
        $out = ['member' => DB::table('members')->where('id', $memberId)->select('id', 'name')->first(),
            'balance' => null, 'expiration_date' => null, 'variable_symbol' => null];

        $credit = Account::where('member_id', $memberId)->where('account_attribute_id', 221100)->first();
        if ($credit) {
            $out['balance']         = (float) $credit->balance;
            $out['expiration_date'] = app(\App\Domains\Billing\Fees\PaidUntil::class)->for($credit);
            $out['variable_symbol'] = (string) ($credit->variableSymbols()->value('variable_symbol') ?? '');
        }
        return $out;
    }
}
