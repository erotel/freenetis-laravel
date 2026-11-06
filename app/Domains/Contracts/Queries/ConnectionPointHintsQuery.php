<?php

namespace App\Domains\Contracts\Queries;

use App\Domains\Billing\Fees\AllowedSubnetFeesResolver;
use App\Domains\Contracts\Repositories\ContractAddonRepository;
use App\Domains\Contracts\Support\AddonType;
use Illuminate\Support\Facades\DB;

/**
 * Nápověda k dodatkům „přípojné místo" pro správce: místa, která nejdou vybrat,
 * a varování k situaci člena. Jen čte, nic nemění.
 */
class ConnectionPointHintsQuery
{
    public function __construct(private ContractAddonRepository $addons)
    {
    }

    /** @return array{unavailable: list<array{id:int,name:string}>, warnings: list<string>} */
    public function forMember(int $memberId, int $contractId): array
    {
        $places = DB::table('allowed_subnets as a')
            ->leftJoin('subnets as s', 's.id', '=', 'a.subnet_id')
            ->leftJoin('speed_classes as sc', 'sc.id', '=', 'a.speed_class_id')
            ->where('a.member_id', $memberId)
            ->orderBy('a.id')
            ->get(['a.id', 'a.subnet_id', 'a.speed_class_id', 'a.charged', 'a.enabled', 's.name', 'sc.name as speed', 'sc.price']);

        // Dodatky jdou od nejnovějšího: první „přidání" místa je to platné.
        $added = [];
        foreach ($this->addons->forContract($contractId, AddonType::ConnectionPoint) as $addon) {
            if ($addon->service_action === 'add' && $addon->allowed_subnet_id) {
                $added[(int) $addon->allowed_subnet_id] ??= $addon;
            }
        }

        $unavailable = [];
        $warnings = [];
        foreach ($places as $place) {
            $name  = (string) ($place->name ?: 'Přípojné místo');
            $addon = $added[(int) $place->id] ?? null;

            if (!$place->speed_class_id) {
                if (!$place->charged) {
                    $unavailable[] = ['id' => (int) $place->id, 'name' => $name];
                }
                continue;
            }

            if ($place->charged && !$place->enabled) {
                $warnings[] = "Místo {$name} je vypnuté, ale dál se účtuje " . $this->price($place->price) . ' Kč měsíčně.';
            }
            if (!$place->charged && $place->enabled) {
                $warnings[] = "Místo {$name} už má v QoS rychlost {$place->speed}, ale neúčtuje se — "
                    . ($addon && $addon->status !== 'signed'
                        ? "účtování začne až podpisem dodatku č. {$addon->addon_no}."
                        : 'nemá dodatek čekající na podpis.');
            }
            if ($addon && ($place->charged || $addon->status !== 'signed')) {
                $device = AllowedSubnetFeesResolver::deviceAddress($memberId, (int) $place->subnet_id);
                if ($device !== '' && !$this->sameAddress($device, (string) $addon->service_name)) {
                    $warnings[] = "Dodatek č. {$addon->addon_no} uvádí adresu {$addon->service_name}, zařízení v podsíti {$name} má {$device}.";
                }
            }
        }

        return ['unavailable' => $unavailable, 'warnings' => $warnings];
    }

    private function price(mixed $price): string
    {
        return number_format((float) $price, 0, ',', ' ');
    }

    /** Stejná adresa; jedna může vynechat ulici (v obcích bez ulic bývá stejná jako obec). */
    private function sameAddress(string $a, string $b): bool
    {
        [$a, $b] = [$this->normalized($a), $this->normalized($b)];

        return $a === $b || str_ends_with($a, ' ' . $b) || str_ends_with($b, ' ' . $a);
    }

    private function normalized(string $address): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $address)));
    }
}
