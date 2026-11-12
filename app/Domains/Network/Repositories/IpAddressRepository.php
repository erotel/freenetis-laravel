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

    /** Popisek rozhraní pro předvyplněný našeptávač: „Zařízení — rozhraní (MAC)". */
    public function ifaceLabel(int $id): ?string
    {
        $r = \Illuminate\Support\Facades\DB::table('ifaces as i')
            ->leftJoin('devices as d', 'd.id', '=', 'i.device_id')
            ->where('i.id', $id)
            ->select('i.name as iface_name', 'i.mac', 'd.name as device_name')
            ->first();
        if (!$r) {
            return null;
        }
        $label = ($r->device_name ?? '(bez zařízení)') . ' — ' . ($r->iface_name ?: 'iface #' . $id);
        return $r->mac ? $label . ' (' . $r->mac . ')' : $label;
    }

    public function memberName(int $id): ?string
    {
        return \Illuminate\Support\Facades\DB::table('members')->where('id', $id)->value('name');
    }

    public function ifaceMac(int $id): ?string
    {
        return \Illuminate\Support\Facades\DB::table('ifaces')->where('id', $id)->value('mac');
    }

    public function firstIfaceIdOfDevice(int $deviceId): ?int
    {
        $v = \Illuminate\Support\Facades\DB::table('ifaces')->where('device_id', $deviceId)->orderBy('name')->value('id');
        return $v !== null ? (int) $v : null;
    }

    public function memberIdOfIface(int $ifaceId): ?int
    {
        $v = \Illuminate\Support\Facades\DB::table('ifaces as i')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('i.id', $ifaceId)->value('u.member_id');
        return $v !== null ? (int) $v : null;
    }

    public function memberIdOfDevice(int $deviceId): ?int
    {
        $v = \Illuminate\Support\Facades\DB::table('devices as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('d.id', $deviceId)->value('u.member_id');
        return $v !== null ? (int) $v : null;
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
    /** Vlastník IP: přímo přiřazený člen, jinak člen vlastníka zařízení (viz IpOwner). */
    public function ownerId(IpAddress $ip): ?int
    {
        return $ip->member_id ? (int) $ip->member_id : $this->deviceOwnerId($ip);
    }

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
