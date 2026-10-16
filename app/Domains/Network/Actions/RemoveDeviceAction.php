<?php

namespace App\Domains\Network\Actions;

use App\Domains\Network\Events\DeviceRemoved;
use App\Domains\Network\Repositories\DeviceRepository;
use App\Models\Device;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Smazání zařízení s rozhraními a IPv4/IPv6. Audit na členovi, DHCP a povolené
 * podsítě řeší listenery {@see DeviceRemoved}. Oprávnění kontroluje volající.
 */
class RemoveDeviceAction
{
    public function __construct(private DeviceRepository $devices)
    {
    }

    /** @throws ModelNotFoundException */
    public function handle(int $deviceId): DeviceRemoved
    {
        $device = Device::with('ifaces.ipAddresses', 'user')->findOrFail($deviceId);

        // Podsítě a IP zjistit předem — po hromadném smazání už nejsou.
        $subnetIds = [];
        $ips       = [];
        foreach ($device->ifaces as $iface) {
            foreach ($iface->ipAddresses as $ip) {
                if ($ip->subnet_id) $subnetIds[$ip->subnet_id] = true;
                if ($ip->ip_address) $ips[] = $ip->ip_address;
            }
        }

        $event = new DeviceRemoved($deviceId, (string) $device->name, (int) $device->user_id,
            $device->user?->member_id, $ips, array_keys($subnetIds));

        $this->devices->delete($device);
        event($event);

        return $event;
    }
}
