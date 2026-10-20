<?php

namespace App\Domains\Billing\Repositories;

use App\Domains\Billing\Fees\FeePolicy;
use Illuminate\Support\Facades\DB;

/**
 * Ceník: `fees` (částky), `members_fees` (přiřazení členovi s platností a
 * prioritou), `enum_types` (druh poplatku), `speed_classes.price` (tarif),
 * `allowed_subnets` (placená přípojná místa). Jen čtení — per člen.
 */
class FeeRepository
{
    /** Aktivní poplatek daného druhu s nejnižší prioritou (číslem), nebo null. */
    public function activeMemberFee(int $memberId, string $feeType, string $date): ?float
    {
        $fee = $this->activeMemberFees($memberId, $feeType, $date)
            ->orderBy('mf.priority')
            ->value('f.fee');

        return $fee === null ? null : (float) $fee;
    }

    public function activeMemberFeeSum(int $memberId, string $feeType, string $date): float
    {
        return (float) $this->activeMemberFees($memberId, $feeType, $date)->sum('f.fee');
    }

    /** @return array<int, array{fee_id: int, name: string, fee: float}> dle priority */
    public function activeMemberFeeItems(int $memberId, string $feeType, string $date): array
    {
        return $this->activeMemberFees($memberId, $feeType, $date)
            ->select('f.id as fee_id', 'f.name', 'f.fee')
            ->orderBy('mf.priority')
            ->get()
            ->map(fn ($r) => ['fee_id' => (int) $r->fee_id, 'name' => (string) $r->name, 'fee' => (float) $r->fee])
            ->all();
    }

    /** Ceníková cena tarifu (speed_classes.price) přiřazeného členovi. */
    public function tarifPrice(int $memberId): ?float
    {
        $price = DB::table('members as m')
            ->join('speed_classes as sc', 'sc.id', '=', 'm.speed_class_id')
            ->where('m.id', $memberId)
            ->value('sc.price');

        return $price === null ? null : (float) $price;
    }

    public function feeAmount(int $feeId): ?float
    {
        $fee = DB::table('fees')->where('id', $feeId)->value('fee');
        return $fee === null ? null : (float) $fee;
    }

    private function activeMemberFees(int $memberId, string $feeType, string $date)
    {
        return DB::table('members_fees as mf')
            ->join('fees as f', 'f.id', '=', 'mf.fee_id')
            ->join('enum_types as et', 'et.id', '=', 'f.type_id')
            ->whereRaw('LOWER(et.value) = ?', [$feeType])
            ->where('mf.member_id', $memberId)
            ->where('mf.activation_date', '<=', $date)
            ->where('mf.deactivation_date', '>=', $date);
    }

    /** Nejstarší „od“ a nejpozdější „do“ pravidelných poplatků ceníku (hranice výpočtu „zaplaceno do“). @return array{0:?string, 1:?string} */
    public function regularFeeBounds(): array
    {
        $q = fn () => DB::table('fees')
            ->join('enum_types', 'enum_types.id', '=', 'fees.type_id')
            ->where('enum_types.value', 'regular member fee');
        return [$q()->min('fees.from'), $q()->max('fees.to')];
    }

    /** Zařízení člena splácená ve srážkách (hlavní uživatel; datum nákupu, cena, splátka). */
    public function devicesWithDebt(int $memberId)
    {
        return DB::table('devices')
            ->where('user_id', function ($q) use ($memberId) {
                $q->select('id')->from('users')->where('member_id', $memberId)->limit(1);
            })
            ->whereNotNull('buy_date')
            ->where('price', '>', 0)
            ->where('payment_rate', '>', 0)
            ->get(['buy_date', 'price', 'payment_rate']);
    }
}
