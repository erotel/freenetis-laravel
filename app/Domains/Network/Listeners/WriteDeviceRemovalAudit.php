<?php

namespace App\Domains\Network\Listeners;

use App\Domains\Network\Events\DeviceRemoved;
use App\Services\AuditLogger;

/**
 * Smazání zařízení na kartu člena: trait zaloguje smazání device-centricky, ale
 * rozhraní/IP se mažou hromadně (bez eventů) a smazané zařízení přes živé ID už
 * nedohledáme — proto navíc member-keyed záznam s IP adresami.
 */
class WriteDeviceRemovalAudit
{
    public function handle(DeviceRemoved $event): void
    {
        if (!$event->memberId) {
            return;
        }
        AuditLogger::log('device_removed', 'members', $event->memberId, [
            'device_id'   => $event->deviceId,
            'device_name' => $event->deviceName,
            'ip_adresy'   => $event->removedIps,
        ], null);
    }
}
