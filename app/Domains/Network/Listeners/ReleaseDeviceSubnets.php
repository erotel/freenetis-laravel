<?php

namespace App\Domains\Network\Listeners;

use App\Domains\Network\Events\DeviceRemoved;
use App\Domains\Network\Support\SubnetAssignmentSync;

/**
 * Po smazání zařízení: DHCP podsítě jeho IP k přegenerování (hromadný delete IP
 * by flag nezměnil) a povolené podsítě člena odebrat, pokud v nich nemá jinou IP.
 */
class ReleaseDeviceSubnets
{
    public function __construct(private SubnetAssignmentSync $subnets)
    {
    }

    public function handle(DeviceRemoved $event): void
    {
        $this->subnets->expireDhcp($event->subnetIds);
        $this->subnets->ipsRemoved($event->memberId, $event->subnetIds);
    }
}
