<?php

namespace App\Domains\Network\Actions;

use App\Domains\Network\Repositories\IpAddressRepository;
use App\Domains\Network\Support\AllowedSubnetSyncService;
use App\Domains\Network\Support\Ip6Sync;
use App\Domains\Network\Support\SubnetDhcpChanges;
use App\Models\IpAddress;

/** Úprava IP: přepočet IPv6, expirace DHCP nové i původní podsítě, přesun přípojného místa. */
class UpdateIpAddressAction
{
    public function __construct(private IpAddressRepository $ips, private AllowedSubnetSyncService $places)
    {
    }

    public function handle(IpAddress $ip, array $data): void
    {
        $oldSubnetId = $ip->subnet_id;
        $oldOwner = $this->ips->ownerId($ip);
        Ip6Sync::delete($ip->iface_id, $ip->ip_address);
        $this->ips->update($ip, $data);
        $ip->unsetRelation('iface');   // vlastník podle nového rozhraní
        Ip6Sync::add($ip->iface_id, $ip->ip_address);

        if ($ip->subnet) {
            SubnetDhcpChanges::expire($ip->subnet);
        }
        if ($oldSubnetId !== $ip->subnet_id) {
            if ($old = $this->ips->subnet($oldSubnetId)) {
                SubnetDhcpChanges::expire($old);
            }
        }

        $owner = $this->ips->ownerId($ip);
        if ($owner && $ip->subnet_id) {
            $this->places->onIpMoved($owner, $oldSubnetId, (int) $ip->subnet_id);
        }
        // jiný vlastník (jiné rozhraní / přímý člen) → původní ztratí místo bez jiné IP
        if ($oldOwner && $oldOwner !== $owner && $oldSubnetId) {
            $this->places->onIpRemoved($oldOwner, (int) $oldSubnetId);
        }
    }
}
