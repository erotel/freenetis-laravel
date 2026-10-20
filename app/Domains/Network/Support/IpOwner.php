<?php

namespace App\Domains\Network\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Vlastník IPv4 adresy — jedno pravidlo pro celý systém (shodně s exportem pro
 * brány): přímo přiřazený člen (ip_addresses.member_id, typicky celý rozsah
 * bez rozhraní, např. 10.133.223.0/24 zásuvek u sdružení), jinak člen
 * uživatele, kterému patří zařízení s rozhraním IP.
 */
final class IpOwner
{
    /** SQL výraz vlastníka nad aliasy ip / u (viz query()). */
    public const SQL = 'COALESCE(ip.member_id, u.member_id)';

    /** ip_addresses as ip s left joiny na rozhraní → zařízení → uživatele (alias u). */
    public static function query(): Builder
    {
        return DB::table('ip_addresses as ip')
            ->leftJoin('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->leftJoin('devices as d', 'd.id', '=', 'i.device_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id');
    }

    /** IP daného člena. */
    public static function ofMember(int $memberId): Builder
    {
        return self::query()->whereRaw(self::SQL . ' = ?', [$memberId]);
    }
}
