<?php

namespace App\Domains\Network\Repositories;

use App\Models\Iface;
use App\Models\IpAddress;
use App\Models\Member;
use App\Models\Subnet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** IPv4 adresy (ip_addresses): na rozhraní zařízení, nebo přidělené přímo členovi. */
class IpAddressRepository
{
    public function paginate(string $search, string $sort, string $dir, int $perPage): LengthAwarePaginator
    {
        return IpAddress::with(['subnet', 'member', 'iface.device'])
            ->when($search !== '', fn ($q) => $q->where('ip_address', 'like', "%{$search}%"))
            ->orderBy($sort, $dir)->paginate($perPage)->withQueryString();
    }

    public function find(int $id): ?IpAddress
    {
        return IpAddress::find($id);
    }

    public function findForDetail(int $id): ?IpAddress
    {
        return IpAddress::with(['subnet', 'member', 'iface.device'])->find($id);
    }

    public function ifacesForSelect(): Collection
    {
        return Iface::with('device')->orderBy('name')->get();
    }

    public function subnetsForSelect(): Collection
    {
        return Subnet::orderBy('network_address')->get();
    }

    public function membersForSelect(): Collection
    {
        return Member::orderBy('name')->get();
    }

    public function subnet(?int $id): ?Subnet
    {
        return $id ? Subnet::find($id) : null;
    }

    public function gatewayExists(int $subnetId, ?int $exceptId = null): bool
    {
        return IpAddress::where('subnet_id', $subnetId)->where('gateway', 1)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
    }

    /** Člen vlastnící zařízení s rozhraním dané IP (přes iface → device → user). */
    public function deviceOwnerId(IpAddress $ip): ?int
    {
        $ip->loadMissing('iface.device.user');
        return (int) ($ip->iface?->device?->user?->member_id ?? 0) ?: null;
    }

    public function ifaceOwnerId(?int $ifaceId): ?int
    {
        return $ifaceId ? Iface::find($ifaceId)?->device?->user?->member_id : null;
    }

    public function create(array $data): IpAddress
    {
        return IpAddress::create($data);
    }

    public function update(IpAddress $ip, array $data): void
    {
        $ip->update($data);
    }

    public function delete(IpAddress $ip): void
    {
        $ip->delete();
    }
}
