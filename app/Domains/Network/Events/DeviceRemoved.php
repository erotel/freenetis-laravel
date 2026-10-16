<?php

namespace App\Domains\Network\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Zařízení bylo smazáno i s rozhraními a IP (dispatch po commitu). */
class DeviceRemoved
{
    use Dispatchable;

    /**
     * @param string[] $removedIps IPv4 adresy, které zařízení mělo
     * @param int[]    $subnetIds  podsítě těchto IP
     */
    public function __construct(
        public readonly int $deviceId,
        public readonly string $deviceName,
        public readonly int $userId,
        public readonly ?int $memberId,
        public readonly array $removedIps,
        public readonly array $subnetIds,
    ) {
    }
}
