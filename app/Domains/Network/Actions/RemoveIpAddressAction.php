<?php

namespace App\Domains\Network\Actions;

use App\Domains\Network\Repositories\IpAddressRepository;
use App\Domains\Network\Support\Ip6Sync;
use App\Models\IpAddress;
use App\Services\AllowedSubnetSyncService;

/** Smaže IP i odvozenou IPv6; expirace DHCP podsítě, přípojné místo bez IP odejde. */
class RemoveIpAddressAction
{
    public function __construct(private IpAddressRepository $ips, private AllowedSubnetSyncService $places)
    {
    }

    public function handle(IpAddress $ip): void
    {
        $subnet   = $ip->subnet;
        $subnetId = $ip->subnet_id;
        $owner    = $this->ips->deviceOwnerId($ip);

        Ip6Sync::delete($ip->ip_address);
        $this->ips->delete($ip);
        $subnet?->setExpired();

        if ($owner && $subnetId) {
            $this->places->onIpRemoved($owner, $subnetId);
        }
    }
}
