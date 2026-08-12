<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Platba per přípojné místo (povolená podsíť) — měsíční poplatek za placené
 * (charged) podsítě člena. Účtuje se bez ohledu na enabled (vypnutí je
 * connectivity toggle, ne způsob, jak se vyhnout platbě) — platbu řídí admin
 * přes charged, zákazník ji vypnutím neobejde.
 *
 * Cena místa = cena jeho VLASTNÍ rychlosti (speed_classes.price přes
 * allowed_subnets.speed_class_id). Účtovat lze jen místo s vlastní rychlostí —
 * zděděná rychlost (speed_class_id NULL) se neúčtuje. Bez ruční částky.
 *
 * Sčítá se do měsíční částky člena vedle tarifu a dodatečných služeb (transfer
 * type 6). DeductFees drží ekvivalentní bulk SQL — musí zůstat v souladu.
 */
class AllowedSubnetFeesResolver
{
    /** Součet cen placených přípojných míst člena. */
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

    /**
     * Placená místa člena pro výběr do dodatku „přípojné místo". Adresa se bere
     * ze zařízení v dané podsíti (device.address_point) — předvyplní se do dodatku.
     * Vrací [['id','subnet_id','name','speed','fee','address'], ...].
     */
    public static function chargedPlaces(int $memberId): array
    {
        return self::baseQuery($memberId)
            ->leftJoin('subnets as s', 's.id', '=', 'a.subnet_id')
            ->selectRaw('a.id, a.subnet_id, s.name as name, sc_place.name as speed, ' . self::EFFECTIVE_FEE_SQL . ' AS fee')
            ->orderBy('a.id')
            ->get()
            ->map(fn ($r) => [
                'id'        => (int) $r->id,
                'subnet_id' => (int) $r->subnet_id,
                'name'      => (string) $r->name,
                'speed'     => (string) ($r->speed ?? ''),
                'fee'       => (float) $r->fee,
                'address'   => self::deviceAddress($memberId, (int) $r->subnet_id),
            ])
            ->all();
    }

    /**
     * Adresa zařízení člena, které má IP v dané podsíti (přes device.address_point).
     * Formát „ulice číslo, obec PSČ" (shodně s výpisem zařízení). '' když není.
     */
    public static function deviceAddress(int $memberId, int $subnetId): string
    {
        $row = DB::table('ip_addresses as ip')
            ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('address_points as ap', 'ap.id', '=', 'd.address_point_id')
            ->leftJoin('streets as st', 'st.id', '=', 'ap.street_id')
            ->leftJoin('towns as tn', 'tn.id', '=', 'ap.town_id')
            ->where('u.member_id', $memberId)
            ->where('ip.subnet_id', $subnetId)
            ->whereNotNull('d.address_point_id')
            ->orderBy('d.id')
            ->first(['st.street', 'ap.street_number', 'tn.town', 'tn.zip_code']);

        if (!$row) {
            return '';
        }
        $street = trim(($row->street ?? '') . ' ' . ($row->street_number ?? ''));
        $town   = trim(($row->town ?? '') . ' ' . ($row->zip_code ?? ''));

        return trim($street . (($street !== '' && $town !== '') ? ', ' : '') . $town);
    }

    /** Cena placeného místa = cena jeho vlastní rychlosti. */
    private const EFFECTIVE_FEE_SQL = 'COALESCE(sc_place.price, 0)';

    private static function baseQuery(int $memberId)
    {
        return DB::table('allowed_subnets as a')
            ->join('speed_classes as sc_place', 'sc_place.id', '=', 'a.speed_class_id') // jen místa s vlastní rychlostí
            ->where('a.member_id', $memberId)
            ->where('a.charged', 1);
    }
}
