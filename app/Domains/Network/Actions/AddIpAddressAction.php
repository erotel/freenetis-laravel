<?php

namespace App\Domains\Network\Actions;

use App\Domains\Network\Repositories\IpAddressRepository;
use App\Domains\Network\Support\AllowedSubnetSyncService;
use App\Domains\Network\Support\Ip6Sync;
use App\Domains\Network\Support\SubnetDhcpChanges;
use App\Domains\Notifications\Actions\SyncPendingCustomerRedirectAction;
use App\Models\IpAddress;

/**
 * Nová IP: odvozená IPv6, expirace DHCP podsítě, přípojné místo vlastníka
 * (přímý člen, jinak vlastník zařízení — IpOwner); čekající zákazník → hned přepočet přesměrování (ne až cron).
 */
class AddIpAddressAction
{
    public function __construct(
        private IpAddressRepository $ips,
        private AllowedSubnetSyncService $places,
        private SyncPendingCustomerRedirectAction $pending,
    ) {
    }

    public function handle(array $data): IpAddress
    {
        $ip = $this->ips->create($data);
        Ip6Sync::add($ip->iface_id, $ip->ip_address);
        if ($ip->subnet) {
            SubnetDhcpChanges::expire($ip->subnet);
        }

        $owner = $this->ips->ownerId($ip);
        if ($owner && $ip->subnet_id) {
            $this->places->onIpAdded($owner, (int) $ip->subnet_id);
        }
        if ($owner) {
            $this->pending->handle($owner);
        }
        return $ip;
    }
}
