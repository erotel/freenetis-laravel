<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Repositories\ContractAddonRepository;
use App\Domains\Contracts\Support\AddonType;
use App\Domains\Contracts\Support\ContractEventLog;
use App\Domains\Contracts\Support\TariffSnapshot;
use App\Models\Contract;
use App\Models\ContractAddon;
use App\Models\Member;
use App\Models\SpeedClass;

/**
 * Návrh dodatku „změna tarifu" k podepsané smlouvě: old_* = současný tarif
 * člena (= poslední podepsaný stav), new_* = navrhovaná rychlost. Rychlost
 * člena se nemění — až podpisem ({@see SignAddonAction}). Opakovatelné.
 */
class CreateTariffAddonAction
{
    public function __construct(
        private ContractAddonRepository $addons,
        private TariffSnapshot $snapshots,
    ) {
    }

    public function handle(int $contractId, int $newSpeedClassId): ?ContractAddon
    {
        $contract = Contract::find($contractId);
        if (!$contract || $contract->status !== 'signed') {
            return null;
        }
        $member = Member::with(['speedClass'])->find($contract->member_id);
        if (!$member || !SpeedClass::whereKey($newSpeedClassId)->exists()) {
            return null;
        }

        $old = $this->snapshots->current($member);
        $new = $this->snapshots->forSpeedClass($member, $newSpeedClassId);

        $addon = $this->addons->createDraft($contract, AddonType::TariffChange, [
            'old_speed_name'           => $old['speed_name'],
            'old_price'                => $old['price'],
            'old_price_after_discount' => $old['price_after_discount'],
            'new_speed_name'           => $new['speed_name'],
            'new_price'                => $new['price'],
            'new_price_after_discount' => $new['price_after_discount'],
            'new_speed_class_id'       => $newSpeedClassId,
            'discount_until'           => $new['discount_until'],
        ]);

        ContractEventLog::record($contractId, 'tariff_addon_created', ['addon_id' => $addon->id, 'by' => auth()->user()?->login]);

        return $addon;
    }
}
