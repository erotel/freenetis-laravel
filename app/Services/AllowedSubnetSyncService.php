<?php

namespace App\Services;

use App\Models\AllowedSubnet;
use App\Models\AllowedSubnetsCount;
use App\Models\IpAddress;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Mirror Kohana `Allowed_subnets_Controller::update_enabled`.
 *
 * Vždy když členovi přibyde/změní se/zmizí IP, přepíšeme mu allowed_subnets
 * seznam stejnou logikou: $toEnable má prioritu na začátku, $toDisable na
 * konci, $toRemove se smaže, prostředek si drží pořadí (enabled DESC,
 * last_update DESC). Pak vrchních N získá enabled=1, zbytek enabled=0,
 * kde N = `allowed_subnets_counts.count` daného člena nebo unlimited.
 */
class AllowedSubnetSyncService
{
    public function updateEnabled(
        int $memberId,
        array $toEnable = [],
        array $toDisable = [],
        array $toRemove = []
    ): void {
        if ($memberId <= 0) return;
        if (!DB::table('members')->where('id', $memberId)->exists()) return;

        $toEnable  = $this->normalizeIds($toEnable);
        $toDisable = $this->normalizeIds($toDisable);
        $toRemove  = $this->normalizeIds($toRemove);

        DB::transaction(function () use ($memberId, $toEnable, $toDisable, $toRemove) {
            $existing = AllowedSubnet::where('member_id', $memberId)
                ->orderByDesc('enabled')
                ->orderByDesc('last_update')
                ->get();

            $middle = [];
            foreach ($existing as $as) {
                if (in_array((int) $as->subnet_id, $toRemove, true)) {
                    $as->delete();
                    continue;
                }
                if (!in_array((int) $as->subnet_id, $toEnable, true)
                 && !in_array((int) $as->subnet_id, $toDisable, true)) {
                    $middle[] = (int) $as->subnet_id;
                }
            }

            $ordered = array_values(array_unique(array_merge($toEnable, $middle, $toDisable)));

            $countRow   = AllowedSubnetsCount::where('member_id', $memberId)->first();
            $maxEnabled = ($countRow && $countRow->count > 0) ? (int) $countRow->count : count($ordered);

            $i = 0;
            foreach ($ordered as $subnetId) {
                AllowedSubnet::updateOrCreate(
                    ['member_id' => $memberId, 'subnet_id' => $subnetId],
                    ['enabled' => ($i < $maxEnabled) ? 1 : 0, 'last_update' => now()]
                );
                $i++;
            }
        });
    }

    /**
     * Convenience: člen získal IP v daném subnetu — přidej subnet do allowed.
     */
    public function onIpAdded(int $memberId, int $subnetId): void
    {
        if ($subnetId > 0) {
            $this->updateEnabled($memberId, [$subnetId]);
        }
    }

    /**
     * Convenience: IP se přesunula z $oldSubnet do $newSubnet.
     * Pokud má člen jiné IP ve starém subnetu, neodebíráme — jen přidáme nový.
     */
    public function onIpMoved(int $memberId, ?int $oldSubnetId, int $newSubnetId): void
    {
        if ($newSubnetId > 0) {
            $this->updateEnabled($memberId, [$newSubnetId]);
        }
        if ($oldSubnetId && $oldSubnetId !== $newSubnetId
            && !$this->memberHasIpInSubnet($memberId, $oldSubnetId)) {
            $this->updateEnabled($memberId, [], [], [$oldSubnetId]);
        }
    }

    /**
     * Convenience: členovi byla smazána IP — pokud v subnetu žádnou jinou nemá,
     * odebereme subnet z allowed.
     */
    public function onIpRemoved(int $memberId, int $subnetId): void
    {
        if ($subnetId <= 0) return;
        if (!$this->memberHasIpInSubnet($memberId, $subnetId)) {
            $this->updateEnabled($memberId, [], [], [$subnetId]);
        }
    }

    private function memberHasIpInSubnet(int $memberId, int $subnetId): bool
    {
        return IpAddress::join('ifaces', 'ifaces.id', '=', 'ip_addresses.iface_id')
            ->join('devices', 'devices.id', '=', 'ifaces.device_id')
            ->join('users', 'users.id', '=', 'devices.user_id')
            ->where('users.member_id', $memberId)
            ->where('ip_addresses.subnet_id', $subnetId)
            ->exists();
    }

    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
