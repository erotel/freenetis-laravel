<?php

namespace App\Domains\Network\Repositories;

use App\Models\Device;
use App\Models\Iface;
use App\Models\IpAddress;
use App\Models\Subnet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Síťová rozhraní zařízení a jejich IPv4 adresy. */
class IfaceRepository
{
    public function paginate(string $sort, string $dir, string $search, int $perPage): LengthAwarePaginator
    {
        return Iface::with('device.user.member')
            ->orderBy($sort, $dir)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('mac', 'like', "%{$search}%")))
            ->paginate($perPage)->withQueryString();
    }

    public function deviceOrFail(int $id): Device
    {
        return Device::findOrFail($id);
    }

    public function create(array $values): Iface
    {
        return Iface::create($values);
    }

    public function findForDetailOrFail(int $id): Iface
    {
        return Iface::with(['device.user.member', 'ipAddresses.subnet', 'vlans'])->findOrFail($id);
    }

    public function findForEditOrFail(int $id): Iface
    {
        return Iface::with(['device', 'ipAddresses.subnet'])->findOrFail($id);
    }

    public function findWithIpsOrFail(int $id): Iface
    {
        return Iface::with('ipAddresses')->findOrFail($id);
    }

    public function findForRemovalOrFail(int $id): Iface
    {
        return Iface::with('ipAddresses.subnet', 'device.user')->findOrFail($id);
    }

    public function subnets(): Collection
    {
        return Subnet::orderBy('name')->get();
    }

    /** Podsítě s prvními 30 volnými IP (nabídka ve formuláři). */
    public function subnetsWithFreeIps(): array
    {
        return Subnet::with('ipAddresses')->get()->map(function ($subnet) {
            $used      = $subnet->ipAddresses->pluck('ip_address')->toArray();
            $network   = ip2long($subnet->network_address);
            $mask      = ip2long($subnet->netmask);
            $broadcast = $network | (~$mask & 0xFFFFFFFF);
            $free      = [];
            for ($ip = $network + 1; $ip < $broadcast && count($free) < 30; $ip++) {
                if (!in_array(long2ip($ip), $used)) {
                    $free[] = long2ip($ip);
                }
            }
            return ['id' => $subnet->id, 'network' => $subnet->network_address, 'mask' => $subnet->netmask, 'label' => $subnet->label, 'freeIps' => $free];
        })->values()->toArray();
    }

    /** Je IP v podsíti už použitá (kromě dané IP)? */
    public function ipTaken(string $ip, int $subnetId, ?int $exceptIpId = null): bool
    {
        return IpAddress::where('ip_address', $ip)->where('subnet_id', $subnetId)
            ->when($exceptIpId !== null, fn ($q) => $q->where('id', '!=', $exceptIpId))->exists();
    }

    public function addIp(int $ifaceId, int $subnetId, string $ip, ?int $memberId): void
    {
        IpAddress::create(['iface_id' => $ifaceId, 'subnet_id' => $subnetId, 'ip_address' => $ip, 'member_id' => $memberId,
            'dhcp' => 0, 'gateway' => 0, 'service' => 0]);
    }

    public function expireDhcpSubnet(int $subnetId): void
    {
        $subnet = Subnet::find($subnetId);
        if ($subnet && $subnet->dhcp) {
            $subnet->setExpired();
        }
    }

    public function hasIpInSubnet(Iface $iface, int $subnetId): bool
    {
        return $iface->ipAddresses()->where('subnet_id', $subnetId)->exists();
    }

    /** Smaže rozhraní i jeho IPv4/IPv6. */
    public function delete(Iface $iface): void
    {
        $iface->ip6Addresses()->delete();
        $iface->ipAddresses()->delete();
        $iface->delete();
    }
}
