<?php

namespace App\Domains\Network\Queries;

use App\Models\Device;

/** Detail zařízení ve Field Mode: IPv4 i IPv6 napříč rozhraními (unikátní) a vlastník. */
class FieldDeviceQuery
{
    /** @return ?array data pro view field.device, null = zařízení neexistuje */
    public function find(int $id): ?array
    {
        $device = Device::with(['ifaces.ipAddresses', 'ifaces.ip6Addresses', 'user.member', 'enumType'])->find($id);
        if (!$device) {
            return null;
        }

        return [
            'device' => $device,
            'ips'    => $device->ifaces->flatMap(fn ($i) => $i->ipAddresses)->pluck('ip_address')->filter()->unique()->values(),
            'ip6s'   => $device->ifaces->flatMap(fn ($i) => $i->ip6Addresses)->pluck('ip_address')->filter()->unique()->values(),
            'member' => $device->user?->member,
        ];
    }
}
