<?php

namespace App\Domains\Contracts\Queries;

use App\Domains\Billing\Fees\AllowedSubnetFeesResolver;
use App\Domains\Billing\Fees\FeePolicy;
use App\Domains\Contracts\Repositories\ContractAddonRepository;
use App\Domains\Contracts\Repositories\ContractRepository;
use App\Domains\Contracts\Support\AddonType;
use App\Models\Fee;
use App\Models\SpeedClass;

/** Data karty smlouvy člena (read model). Oprávnění řeší volající. */
class ContractCardQuery
{
    public function __construct(
        private ContractRepository $contracts,
        private ContractAddonRepository $addons,
        private FeePolicy $fees,
    ) {
    }

    /** @return array<string, mixed> proměnné pro view contracts.show (bez oprávnění a člena) */
    public function data(int $memberId): array
    {
        $contract = $this->contracts->forMember($memberId);
        $addons   = fn (AddonType $t) => $contract ? $this->addons->forContract($contract->id, $t) : collect();

        // Aktivní služby člena (pro dodatek ZRUŠENÍ) a dostupné k PŘIDÁNÍ (typ
        // „additional service", nearchivované, člen je ještě nemá).
        $assignableServices = $this->fees->additionalServiceItems($memberId, now()->toDateString());
        $activeFeeIds       = array_column($assignableServices, 'fee_id');

        return [
            'contract'              => $contract,
            'tariffAddons'          => $addons(AddonType::TariffChange),
            'serviceAddons'         => $addons(AddonType::AdditionalService),
            'connectionPointAddons' => $addons(AddonType::ConnectionPoint),
            'assignableServices'    => $assignableServices,
            'availableServices'     => Fee::query()
                ->whereHas('enumType', fn ($q) => $q->whereRaw("LOWER(value) = 'additional service'"))
                ->where('archived', 0)
                ->whereNotIn('id', $activeFeeIds ?: [0])
                ->orderBy('name')
                ->get(['id', 'name', 'fee']),
            // Účtovaná místa (ZRUŠENÍ) a neúčtovaná s vlastní rychlostí (PŘIDÁNÍ).
            'assignablePlaces'      => AllowedSubnetFeesResolver::chargedPlaces($memberId),
            'availablePlaces'       => AllowedSubnetFeesResolver::chargeablePlaces($memberId),
            'speedClasses'          => SpeedClass::orderBy('name')->get(),
        ];
    }
}
