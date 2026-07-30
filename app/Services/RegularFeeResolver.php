<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Jediná pravda o tom, kolik má člen platit pravidelný měsíční poplatek.
 *
 * Pořadí (třístupňový fallback):
 *   1. individuální poplatek  — members_fees, aktivní 'regular member fee' (sleva/výjimka)
 *   2. cena tarifu            — speed_classes.price podle members.speed_class_id
 *   3. základní poplatek      — Setting default_fee_member_type_<type> → fees.fee
 *
 * Návratová hodnota:
 *   float  = částka k účtování (i 0.0 = osvobozeno; NEpropadá dál)
 *   null   = na ŽÁDNÉ úrovni není hodnota (nic k účtování)
 *
 * Rozdíl null vs 0 je záměrný: NULL cena tarifu propadá na základní,
 * ale explicitní 0 (individuální i tarif) znamená "neúčtovat nic".
 *
 * POZOR: DeductFees::deductMemberFees a NotificationActivation mirrorují
 * toto pořadí přímo v bulk SQL (kvůli výkonu nad všemi členy). Když se
 * změní pořadí tady, změň i tam.
 */
class RegularFeeResolver
{
    public static function amount(int $memberId, int $memberType, string $date): ?float
    {
        $individual = self::individualFee($memberId, $date);
        if ($individual !== null) {
            return $individual;
        }

        $tarif = self::tarifPrice($memberId);
        if ($tarif !== null) {
            return $tarif;
        }

        return self::defaultFeeByType($memberType);
    }

    /** Úroveň 1: individuální 'regular member fee' z members_fees (nejnižší priorita číslem). */
    public static function individualFee(int $memberId, string $date): ?float
    {
        $fee = DB::table('members_fees as mf')
            ->join('fees as f', 'f.id', '=', 'mf.fee_id')
            ->join('enum_types as et', 'et.id', '=', 'f.type_id')
            ->whereRaw('LOWER(et.value) = ?', ['regular member fee'])
            ->where('mf.member_id', $memberId)
            ->where('mf.activation_date', '<=', $date)
            ->where('mf.deactivation_date', '>=', $date)
            ->orderBy('mf.priority')
            ->value('f.fee');

        return $fee === null ? null : (float) $fee;
    }

    /** Úroveň 2: ceníková cena tarifu (speed_classes.price) přiřazeného člena. */
    public static function tarifPrice(int $memberId): ?float
    {
        $price = DB::table('members as m')
            ->join('speed_classes as sc', 'sc.id', '=', 'm.speed_class_id')
            ->where('m.id', $memberId)
            ->value('sc.price');

        return $price === null ? null : (float) $price;
    }

    /**
     * Úroveň 3: základní poplatek dle typu člena (setting drží fee_id, ne částku).
     * Čekající typy nemají vlastní default — mapují se na cílový typ po aktivaci:
     * žadatel/čekající zákazník (1, 18) → zákazník (2), čekající člen (17) → člen (90).
     * Bez toho by smlouva/QR žadatele (typ 18) ukazovaly 0.
     */
    public static function defaultFeeByType(int $memberType): ?float
    {
        $billingType = match ($memberType) {
            1, 18   => 2,
            17      => 90,
            default => $memberType,
        };
        $feeId = (int) Setting::get("default_fee_member_type_{$billingType}", 0);
        if ($feeId <= 0) {
            return null;
        }
        $fee = DB::table('fees')->where('id', $feeId)->value('fee');

        return $fee === null ? null : (float) $fee;
    }
}
