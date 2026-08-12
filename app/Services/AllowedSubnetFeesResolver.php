<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Platba per přípojné místo (povolená podsíť) — měsíční poplatek za placené
 * (charged) podsítě člena. Účtuje se bez ohledu na enabled (vypnutí je
 * connectivity toggle, ne způsob, jak se vyhnout platbě) — platbu řídí admin
 * přes charged, zákazník ji vypnutím neobejde.
 *
 * Efektivní cena místa:
 *   fee_override            (pokud vyplněno ruční částkou), jinak
 *   cena rychlosti místa    (speed_classes.price přes allowed_subnets.speed_class_id), jinak
 *   cena rychlosti člena    (speed_classes.price přes members.speed_class_id), jinak 0.
 *
 * Sčítá se do měsíční částky člena vedle tarifu a dodatečných služeb (transfer
 * type 6). DeductFees drží ekvivalentní bulk SQL — musí zůstat v souladu.
 */
class AllowedSubnetFeesResolver
{
    /** Součet cen placených zapnutých přípojných míst člena. */
    public static function total(int $memberId): float
    {
        return (float) self::baseQuery($memberId)
            ->selectRaw('COALESCE(SUM(' . self::EFFECTIVE_FEE_SQL . '), 0) AS total')
            ->value('total');
    }

    /**
     * Rozpis placených míst pro kartu člena / seznam podsítí.
     * Vrací [['subnet_id'=>int,'name'=>string,'fee'=>float], ...].
     */
    public static function items(int $memberId): array
    {
        return self::baseQuery($memberId)
            ->leftJoin('subnets as s', 's.id', '=', 'a.subnet_id')
            ->selectRaw('a.subnet_id, s.name as name, ' . self::EFFECTIVE_FEE_SQL . ' AS fee')
            ->orderBy('a.id')
            ->get()
            ->map(fn ($r) => ['subnet_id' => (int) $r->subnet_id, 'name' => (string) $r->name, 'fee' => (float) $r->fee])
            ->all();
    }

    /** SQL výraz efektivní ceny jednoho placeného místa (viz docblock třídy). */
    private const EFFECTIVE_FEE_SQL =
        'COALESCE(a.fee_override, sc_place.price, sc_member.price, 0)';

    private static function baseQuery(int $memberId)
    {
        return DB::table('allowed_subnets as a')
            ->leftJoin('speed_classes as sc_place', 'sc_place.id', '=', 'a.speed_class_id')
            ->leftJoin('members as m', 'm.id', '=', 'a.member_id')
            ->leftJoin('speed_classes as sc_member', 'sc_member.id', '=', 'm.speed_class_id')
            ->where('a.member_id', $memberId)
            ->where('a.charged', 1);
    }
}
