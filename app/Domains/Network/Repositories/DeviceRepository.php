<?php

namespace App\Domains\Network\Repositories;

use App\Domains\Network\Support\Ip6FromIpv4;
use App\Models\Device;
use App\Models\DeviceEngineer;
use App\Models\Iface;
use App\Models\Ip6Address;
use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Zařízení v legacy schématu (devices → ifaces → ip_addresses / ip6_addresses,
 * device_engineers). Zařízení patří uživateli (devices.user_id) — v praxi
 * hlavnímu uživateli člena.
 */
class DeviceRepository
{
    /** Hlavní uživatel člena (nejnižší id při víc legacy hlavních uživatelích), nebo null. */
    public function mainUserIdOfMember(int $memberId): ?int
    {
        $id = DB::table('users')
            ->where('member_id', $memberId)
            ->where('type', User::MAIN_USER)
            ->orderBy('id')
            ->value('id');
        return $id ? (int) $id : null;
    }

    public function memberIdOfUser(?int $userId): ?int
    {
        if (!$userId) {
            return null;
        }
        $id = DB::table('users')->where('id', $userId)->value('member_id');
        return $id ? (int) $id : null;
    }

    public function create(array $attributes): Device
    {
        return Device::create($attributes);
    }

    public function createIface(int $deviceId, int $type, ?string $name, ?string $mac): Iface
    {
        return Iface::create(['device_id' => $deviceId, 'type' => $type, 'name' => $name, 'mac' => $mac]);
    }

    /** Klientská IPv4 na rozhraní (bez DHCP/brány/služby) + odvozená IPv6 (jen 10.x). */
    public function assignClientIp(int $ifaceId, int $subnetId, string $ip, ?int $memberId): void
    {
        IpAddress::create([
            'iface_id'   => $ifaceId,
            'subnet_id'  => $subnetId,
            'ip_address' => $ip,
            'member_id'  => $memberId,
            'dhcp'       => 0,
            'gateway'    => 0,
            'service'    => 0,
        ]);
        $ip6 = Ip6FromIpv4::derive($ip);
        if ($ip6) {
            Ip6Address::create(['iface_id' => $ifaceId, 'ip_address' => $ip6, 'service' => 0]);
        }
    }

    public function ipTakenInSubnet(string $ip, int $subnetId): bool
    {
        return IpAddress::where('ip_address', $ip)->where('subnet_id', $subnetId)->exists();
    }

    /** Podsítě, ve kterých má zařízení IPv4. @return int[] */
    public function subnetIdsOfDevice(int $deviceId): array
    {
        return IpAddress::join('ifaces', 'ifaces.id', '=', 'ip_addresses.iface_id')
            ->where('ifaces.device_id', $deviceId)
            ->whereNotNull('ip_addresses.subnet_id')
            ->distinct()->pluck('ip_addresses.subnet_id')->all();
    }

    /** Smaže zařízení s rozhraními a jejich IPv4/IPv6 (hromadně, bez model eventů rozhraní/IP). */
    public function delete(Device $device): void
    {
        DB::transaction(function () use ($device) {
            foreach ($device->ifaces as $iface) {
                $iface->ip6Addresses()->delete();
                $iface->ipAddresses()->delete();
            }
            $device->ifaces()->delete();
            $device->delete();
        });
    }

    /** Přiřadí technika k zařízení (idempotentně). */
    public function addEngineer(int $deviceId, int $userId): void
    {
        $exists = DeviceEngineer::where('device_id', $deviceId)->where('user_id', $userId)->exists();
        if (!$exists) {
            DeviceEngineer::create(['device_id' => $deviceId, 'user_id' => $userId]);
        }
    }

    /** Odebere technika (hromadný delete — bez model eventů). */
    public function removeEngineer(int $deviceId, int $userId): void
    {
        DeviceEngineer::where('device_id', $deviceId)->where('user_id', $userId)->delete();
    }
}
