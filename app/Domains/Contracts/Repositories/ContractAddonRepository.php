<?php

namespace App\Domains\Contracts\Repositories;

use App\Domains\Contracts\Support\AddonType;
use App\Models\Contract;
use App\Models\ContractAddon;

/** Dodatky ke smlouvám (contractsdb.contract_addons). */
class ContractAddonRepository
{
    /** Dodatky daného typu ke smlouvě, nejnovější první. */
    public function forContract(int $contractId, AddonType $type)
    {
        return ContractAddon::where('contract_id', $contractId)
            ->where('type', $type->value)
            ->orderByDesc('id')
            ->get();
    }

    /** Dodatek libovolného typu. */
    public function findAny(int $id): ?ContractAddon
    {
        return ContractAddon::find($id);
    }

    public function find(int $id, AddonType $type): ?ContractAddon
    {
        return ContractAddon::where('type', $type->value)->find($id);
    }

    /** Pořadové číslo dalšího dodatku — navazuje i na legacy dodatek č. 1 (contracts.addon). */
    public function nextAddonNo(Contract $contract): int
    {
        $maxNo = (int) (ContractAddon::where('contract_id', $contract->id)->max('addon_no') ?? 0);
        return max($maxNo, $contract->addon ? 1 : 0) + 1;
    }

    /** Nový dodatek jako NÁVRH s účinností předvyplněnou na dnešek (skutečná = den podpisu/vydání). */
    public function createDraft(Contract $contract, AddonType $type, array $attributes): ContractAddon
    {
        return ContractAddon::create(['contract_id' => $contract->id, 'type' => $type->value, 'addon_no' => $this->nextAddonNo($contract)]
            + $attributes + [
                'effective_date' => now()->toDateString(),
                'status'         => 'draft',
                'created_by'     => auth()->user()?->login,
                'created_at'     => now()->format('Y-m-d H:i:s'),
            ]);
    }
}
