<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Automatické datumové ukončení individuálních poplatků člena při ukončení
 * členství / smlouvy.
 *
 * Při odchodu člena se jeho aktivní `members_fees` (tarif, dodatečné služby,
 * osvobození…) nastaví `deactivation_date = leaving_date`, aby po odchodu
 * přestaly platit i účtovat (DeductFees strhává jen aktivní poplatky). Bez toho
 * bývalí členové zůstávali viset s „aktivním" tarifem a musela se řešit ručně.
 *
 * Výjimka: poplatek „Přerušení členství" (special_type_id=1) se nedotýká — má
 * vlastní datové ohraničení a logiku (MembershipInterruptController).
 */
class MemberFeesTermination
{
    private const INTERRUPT_SPECIAL_TYPE = 1;

    /**
     * Ukončí aktivní individuální poplatky člena k datu odchodu.
     * Cílí jen na poplatky, které k datu odchodu ještě platí
     * (activation ≤ leaving < deactivation). Vrací počet upravených řádků.
     */
    public static function deactivate(int $memberId, ?string $leavingDate): int
    {
        if (!self::isRealDate($leavingDate)) {
            return 0;
        }

        return DB::table('members_fees')
            ->where('member_id', $memberId)
            ->where('activation_date', '<=', $leavingDate)
            ->where('deactivation_date', '>', $leavingDate)
            ->whereNotIn('fee_id', self::interruptFeeIds())
            ->update(['deactivation_date' => $leavingDate]);
    }

    /**
     * Zpětné oživení při obnovení členství: poplatky ukončené PŘESNĚ k datu
     * odchodu (tj. naší automatikou) vrátí na „napořád" (9999-12-31). Poplatky
     * ukončené ručně k jinému datu nechá být. Vrací počet upravených řádků.
     */
    public static function reactivate(int $memberId, ?string $leavingDate): int
    {
        if (!self::isRealDate($leavingDate)) {
            return 0;
        }

        return DB::table('members_fees')
            ->where('member_id', $memberId)
            ->where('deactivation_date', $leavingDate)
            ->whereNotIn('fee_id', self::interruptFeeIds())
            ->update(['deactivation_date' => '9999-12-31']);
    }

    /** ID poplatků typu „Přerušení členství" (special_type_id=1). */
    private static function interruptFeeIds(): array
    {
        return DB::table('fees')->where('special_type_id', self::INTERRUPT_SPECIAL_TYPE)->pluck('id')->all();
    }

    private static function isRealDate(?string $d): bool
    {
        return $d !== null && $d !== '' && $d !== '0000-00-00' && $d !== '9999-12-31';
    }
}
