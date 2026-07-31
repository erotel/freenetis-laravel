<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Dodatečné služby (enum_types 'additional service', dříve 'penalty') —
 * měsíční poplatky navíc k tarifu (např. veřejná IP), přiřazené členovi
 * přes members_fees. Strhávají se samostatnou transakcí (transfer type 6),
 * viz DeductFees::deductAdditionalServiceFees.
 *
 * Tohle je per-member zdroj pravdy pro backcharge (PaymentBackchargeService)
 * i zobrazení na kartě člena. DeductFees drží ekvivalentní bulk SQL kvůli
 * výkonu nad všemi členy najednou — musí zůstat v souladu s touto logikou.
 */
class AdditionalServicesResolver
{
    /** Součet všech aktivních dodatečných služeb člena k danému datu. */
    public static function total(int $memberId, string $date): float
    {
        return (float) self::baseQuery($memberId, $date)->sum('f.fee');
    }

    /**
     * Seznam aktivních služeb pro rozpis na kartě člena a výběr do dodatku.
     * Vrací [['fee_id' => int, 'name' => string, 'fee' => float], ...] dle priority.
     */
    public static function items(int $memberId, string $date): array
    {
        return self::baseQuery($memberId, $date)
            ->select('f.id as fee_id', 'f.name', 'f.fee')
            ->orderBy('mf.priority')
            ->get()
            ->map(fn ($r) => ['fee_id' => (int) $r->fee_id, 'name' => (string) $r->name, 'fee' => (float) $r->fee])
            ->all();
    }

    private static function baseQuery(int $memberId, string $date)
    {
        return DB::table('members_fees as mf')
            ->join('fees as f', 'f.id', '=', 'mf.fee_id')
            ->join('enum_types as et', 'et.id', '=', 'f.type_id')
            ->whereRaw("LOWER(et.value) = 'additional service'")
            ->where('mf.member_id', $memberId)
            ->where('mf.activation_date', '<=', $date)
            ->where('mf.deactivation_date', '>=', $date);
    }
}
