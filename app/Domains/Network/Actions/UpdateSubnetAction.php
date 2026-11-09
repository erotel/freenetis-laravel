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
        // Pole, ze kterých se skládá DHCP export (síť, maska, název serveru, DNS,
        // IPoE = bez statických leasů + use-radius). Změna kteréhokoli = nová
        // konfigurace ke stažení. Dřív jen síť/maska → přepnutí IPoE se k MikroTiku
        // nedostalo (204 „beze změny"), incident 2026-11-09 subnet 1479.
        $exportFields = ['network_address', 'netmask', 'name', 'dns', 'ipoe', 'dhcp'];
        $norm = fn ($v) => is_bool($v) ? (int) $v : (string) $v;
        $before = [];
        foreach ($exportFields as $f) {
            $before[$f] = $norm($subnet->{$f});
        }
        $this->subnets->update($subnet, $data);

        $exportChanged = false;
        foreach ($exportFields as $f) {
            $exportChanged = $exportChanged || $before[$f] !== $norm($subnet->{$f});
        }
        // Jen u zapnutého DHCP (při vypnutí se dhcp_expired výše naopak nuluje).
        if ($exportChanged && $subnet->dhcp) {
            SubnetDhcpChanges::expire($subnet);
        }

        // Zapnutí/vypnutí IPoE (dhcp+ipoe) mění, co RADIUS vrací → Kea host-cache.
        if ($wasIpoe !== ($subnet->dhcp && $subnet->ipoe)) {
            IpoeReservationsChanged::dispatch("subnet:{$subnet->id}", true);
        }
    }
}
