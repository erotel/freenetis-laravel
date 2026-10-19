<?php

namespace App\Domains\Billing\Repositories;

use App\Models\Fee;
use App\Models\MemberFee;
use Illuminate\Support\Collection;

/** Přiřazení tarifů členům (members_fees) a výběr přiřaditelných tarifů. */
class MemberFeeRepository
{
    /** Tarify člena s typem, nejnovější aktivace první. */
    public function forMember(int $memberId): Collection
    {
        return MemberFee::where('member_id', $memberId)
            ->with('fee.enumType')
            ->orderBy('activation_date', 'desc')
            ->get();
    }

    public function findOrFail(int $id): MemberFee
    {
        return MemberFee::findOrFail($id);
    }

    public function findWithMemberAndFeeOrFail(int $id): MemberFee
    {
        return MemberFee::with(['member', 'fee'])->findOrFail($id);
    }

    public function fee(int $feeId): ?Fee
    {
        return Fee::find($feeId);
    }

    /** Nearchivované, needitovatelné jen „osvobozen od poplatku". */
    public function assignableFees(): Collection
    {
        return Fee::assignable()->orderBy('type_id')->orderBy('name')->get();
    }

    public function create(array $data): MemberFee
    {
        return MemberFee::create($data);
    }

    public function update(MemberFee $memberFee, array $data): void
    {
        $memberFee->update($data);
    }

    public function delete(MemberFee $memberFee): void
    {
        $memberFee->delete();
    }
}
