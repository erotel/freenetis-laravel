<?php

namespace App\Domains\Network\Actions;

use App\Domains\Network\Repositories\IpAddressRepository;
use App\Domains\Network\Support\AllowedSubnetSyncService;
use App\Domains\Network\Support\Ip6Sync;
use App\Models\IpAddress;

/** Smaže IP i odvozenou IPv6 rozhraní; expirace DHCP podsítě, vlastníkovi bez další IP v podsíti odejde přípojné místo. */
class RemoveIpAddressAction
{
    public function __construct(private IpAddressRepository $ips, private AllowedSubnetSyncService $places)
    {
    }

    public function handle(IpAddress $ip): void
    {
        $subnet   = $ip->subnet;
        $subnetId = $ip->subnet_id;
        $owner    = $this->ips->ownerId($ip);

        Ip6Sync::delete($ip->iface_id, $ip->ip_address);
        $this->ips->delete($ip);
        $subnet?->setExpired();

        if ($owner && $subnetId) {
            $this->places->onIpRemoved($owner, $subnetId);
        }
    }
}
