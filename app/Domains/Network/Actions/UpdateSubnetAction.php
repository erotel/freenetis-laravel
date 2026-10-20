<?php

namespace App\Domains\Network\Actions;

use App\Domains\Access\Events\IpoeReservationsChanged;
use App\Domains\Network\Repositories\SubnetRepository;
use App\Domains\Network\Support\SubnetDhcpChanges;
use App\Models\Subnet;

/** Úprava podsítě — příznaky, které admin bez jemného práva neposlal, zůstávají. */
class UpdateSubnetAction
{
    public function __construct(private SubnetRepository $subnets)
    {
    }

    public function handle(Subnet $subnet, array $data): void
    {
        // Když admin vypne DHCP na subnetu, vyresetuj i dhcp_expired flag —
        // jinak by zůstal viset na 1 (export ho resetuje jen u subnet.dhcp=1)
        // a zařízení by se v DHCP listing tvářilo trvale jako 'Změněno'.
        // Efektivní stav dhcp: když ho uživatel bez práva neposlal (odstraněno
        // z $data), zůstává původní hodnota subnetu.
        $effectiveDhcp = array_key_exists('dhcp', $data) ? $data['dhcp'] : $subnet->dhcp;
        if (!$effectiveDhcp && $subnet->dhcp_expired) {
            $data['dhcp_expired'] = 0;
        }

        $wasIpoe = $subnet->dhcp && $subnet->ipoe;
        $rangeChanged = $data['network_address'] !== $subnet->network_address || $data['netmask'] !== $subnet->netmask;
        $this->subnets->update($subnet, $data);

        // jiná síť / maska = jiná konfigurace DHCP serveru → ke stažení
        if ($rangeChanged && $subnet->dhcp) {
            SubnetDhcpChanges::expire($subnet);
        }

        // Zapnutí/vypnutí IPoE (dhcp+ipoe) mění, co RADIUS vrací → Kea host-cache.
        if ($wasIpoe !== ($subnet->dhcp && $subnet->ipoe)) {
            IpoeReservationsChanged::dispatch("subnet:{$subnet->id}", true);
        }
    }
}
