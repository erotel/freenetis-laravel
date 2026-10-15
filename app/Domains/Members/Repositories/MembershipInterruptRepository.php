<?php

namespace App\Domains\Members\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * Přerušení členství = individuální poplatek se special_type_id=1
 * (members_fees) + řádek membership_interrupts (plánované ukončení po přerušení).
 */
class MembershipInterruptRepository
{
    private const INTERRUPT_SPECIAL_TYPE = 1;

    /** members_fees.id přerušení aktivního k datu, nebo null. */
    public function activeInterruptFeeId(int $memberId, string $date): ?int
    {
        $id = DB::table('members_fees as mf')
            ->join('fees as f', 'f.id', '=', 'mf.fee_id')
            ->where('mf.member_id', $memberId)
            ->where('f.special_type_id', self::INTERRUPT_SPECIAL_TYPE)
            ->where('mf.activation_date', '<=', $date)
            ->where('mf.deactivation_date', '>=', $date)
            ->value('mf.id');

        return $id ? (int) $id : null;
    }

    /** Ukončí přerušení posledním dnem $lastDay a zruší plánované ukončení členství po něm. */
    public function endInterrupt(int $membersFeeId, string $lastDay): void
    {
        DB::table('members_fees')->where('id', $membersFeeId)->update(['deactivation_date' => $lastDay]);
        DB::table('membership_interrupts')->where('members_fee_id', $membersFeeId)->update(['end_after_interrupt_end' => 0]);
    }
}
