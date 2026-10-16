<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Repositories\ContractAddonRepository;
use App\Domains\Contracts\Support\AddonType;
use App\Domains\Contracts\Support\ContractEventLog;
use App\Models\Contract;
use App\Models\ContractAddon;
use Illuminate\Support\Facades\DB;

/**
 * Návrh dodatku „přípojné místo" ($action add|remove): místo (allowed_subnets)
 * musí patřit členovi smlouvy; rychlost a cena z vlastní rychlosti místa, do
 * dodatku jde ADRESA připojení (povinná, ne název podsítě). Účtování místa se
 * mění až podpisem / vydáním.
 */
class CreateConnectionPointAddonAction
{
    public function __construct(private ContractAddonRepository $addons)
    {
    }

    public function handle(int $contractId, int $allowedSubnetId, string $action = 'add', string $address = ''): ?ContractAddon
    {
        $contract = Contract::find($contractId);
        if (!$contract || $contract->status !== 'signed' || !in_array($action, ['add', 'remove'], true)) {
            return null;
        }

        $place = DB::table('allowed_subnets as a')
            ->leftJoin('subnets as s', 's.id', '=', 'a.subnet_id')
            ->leftJoin('speed_classes as sc_place', 'sc_place.id', '=', 'a.speed_class_id')
            ->where('a.id', $allowedSubnetId)
            ->where('a.member_id', $contract->member_id)
            ->first(['s.name as subnet_name', 'sc_place.name as place_speed', 'sc_place.price as place_price']);
        if (!$place) {
            return null;
        }

        $address = trim($address);
        if ($address === '') {
            return null;
        }

        $addon = $this->addons->createDraft($contract, AddonType::ConnectionPoint, [
            'service_name'      => $address,
            'service_price'     => (float) ($place->place_price ?? 0),
            'service_action'    => $action,
            'place_speed_name'  => $place->place_speed,
            'allowed_subnet_id' => $allowedSubnetId,
        ]);

        ContractEventLog::record($contractId, 'connection_point_addon_created', [
            'addon_id'          => $addon->id,
            'action'            => $action,
            'allowed_subnet_id' => $allowedSubnetId,
            'by'                => auth()->user()?->login,
        ]);

        return $addon;
    }
}
