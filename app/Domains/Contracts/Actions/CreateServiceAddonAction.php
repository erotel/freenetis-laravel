<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Repositories\ContractAddonRepository;
use App\Domains\Contracts\Support\AddonType;
use App\Domains\Contracts\Support\ContractEventLog;
use App\Models\Contract;
use App\Models\ContractAddon;
use Illuminate\Support\Facades\DB;

/**
 * Návrh dodatku „dodatečná služba" ($action add|remove) k podepsané smlouvě;
 * název a cenu snímá z poplatku typu 'additional service'. Členovi se nic
 * nemění — přidání až podpisem, zrušení až vydáním.
 */
class CreateServiceAddonAction
{
    public function __construct(private ContractAddonRepository $addons)
    {
    }

    public function handle(int $contractId, int $feeId, string $action = 'add'): ?ContractAddon
    {
        $contract = Contract::find($contractId);
        if (!$contract || $contract->status !== 'signed' || !in_array($action, ['add', 'remove'], true)) {
            return null;
        }

        $fee = DB::table('fees as f')
            ->join('enum_types as et', 'et.id', '=', 'f.type_id')
            ->whereRaw("LOWER(et.value) = 'additional service'")
            ->where('f.id', $feeId)
            ->first(['f.name', 'f.fee']);
        if (!$fee) {
            return null;
        }

        $addon = $this->addons->createDraft($contract, AddonType::AdditionalService, [
            'service_name'   => $fee->name !== '' ? $fee->name : 'Dodatečná služba',
            'service_price'  => (float) $fee->fee,
            'service_action' => $action,
            'fee_id'         => $feeId,
        ]);

        ContractEventLog::record($contractId, 'service_addon_created', [
            'addon_id' => $addon->id,
            'action'   => $action,
            'fee_id'   => $feeId,
            'by'       => auth()->user()?->login,
        ]);

        return $addon;
    }
}
