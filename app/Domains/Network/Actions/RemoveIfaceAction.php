<?php

namespace App\Domains\Network\Actions;

use App\Domains\Network\Repositories\IfaceRepository;
use App\Domains\Network\Support\AllowedSubnetSyncService;
use App\Domains\Network\Support\SubnetDhcpChanges;
use App\Models\Iface;

/** Smaže rozhraní s IP; DHCP podsítě expirují, přípojná místa člena bez IP odejdou. */
class RemoveIfaceAction
{
    public function __construct(private IfaceRepository $ifaces, private AllowedSubnetSyncService $places)
    {
    }

    public function handle(Iface $iface): void
    {
        $memberId = $iface->device?->user?->member_id;
        $touched  = [];
        foreach ($iface->ipAddresses as $ip) {
            if ($ip->subnet && $ip->subnet->dhcp) {
                SubnetDhcpChanges::expire($ip->subnet);
            }
            if ($ip->subnet_id) {
                $touched[$ip->subnet_id] = true;
            }
        }

        $this->ifaces->delete($iface);

        if ($memberId) {
            foreach (array_keys($touched) as $sid) {
                $this->places->onIpRemoved($memberId, (int) $sid);
            }
        }
    }
}
