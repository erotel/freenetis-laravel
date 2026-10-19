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

    /** Přerušení s intervalem z poplatku (mi.* + activation/deactivation_date), nebo null. */
    public function find(int $id): ?object
    {
        return DB::table('membership_interrupts as mi')
            ->join('members_fees as mf', 'mf.id', '=', 'mi.members_fee_id')
            ->where('mi.id', $id)
            ->select('mi.*', 'mf.activation_date', 'mf.deactivation_date')
            ->first();
    }

    /** Id poplatku „přerušení členství" (special_type_id 1), nebo null. */
    public function interruptFeeId(): ?int
    {
        $id = DB::table('fees')->where('special_type_id', self::INTERRUPT_SPECIAL_TYPE)->value('id');
        return $id ? (int) $id : null;
    }

    /** Překrývá se interval s jiným přerušením člena (volitelně kromě jednoho)? */
    public function overlaps(int $memberId, string $from, string $to, ?int $ignoreInterruptId = null): bool
    {
        return DB::table('members_fees as mf')
            ->join('membership_interrupts as mi', 'mi.members_fee_id', '=', 'mf.id')
            ->join('fees as f', 'f.id', '=', 'mf.fee_id')
            ->where('mf.member_id', $memberId)
            ->where('f.special_type_id', self::INTERRUPT_SPECIAL_TYPE)
            ->where('mf.activation_date', '<=', $to)
            ->where(function ($q) use ($from) {
                $q->whereNull('mf.deactivation_date')->orWhere('mf.deactivation_date', '>=', $from);
            })
            ->when($ignoreInterruptId, fn ($q) => $q->where('mi.id', '!=', $ignoreInterruptId))
            ->exists();
    }

    public function create(int $memberId, int $feeId, string $from, string $to, string $comment, bool $endAfter): void
    {
        $membersFeeId = DB::table('members_fees')->insertGetId([
            'fee_id'            => $feeId,
            'member_id'         => $memberId,
            'activation_date'   => $from,
            'deactivation_date' => $to,
            'priority'          => 0,
            'comment'           => '',
        ]);
        DB::table('membership_interrupts')->insert([
            'member_id'               => $memberId,
            'members_fee_id'          => $membersFeeId,
            'comment'                 => $comment,
            'end_after_interrupt_end' => $endAfter ? 1 : 0,
        ]);
    }

    public function update(object $interrupt, string $from, string $to, string $comment, bool $endAfter): void
    {
        DB::table('members_fees')->where('id', $interrupt->members_fee_id)->update(['activation_date' => $from, 'deactivation_date' => $to]);
        DB::table('membership_interrupts')->where('id', $interrupt->id)->update(['comment' => $comment, 'end_after_interrupt_end' => $endAfter ? 1 : 0]);
    }

    public function delete(object $interrupt): void
    {
        DB::table('membership_interrupts')->where('id', $interrupt->id)->delete();
        DB::table('members_fees')->where('id', $interrupt->members_fee_id)->delete();
    }

    public function leavingDate(int $memberId): mixed
    {
        return DB::table('members')->where('id', $memberId)->value('leaving_date');
    }

    public function setLeavingDate(int $memberId, ?string $date): void
    {
        DB::table('members')->where('id', $memberId)->update(['leaving_date' => $date]);
    }
}
