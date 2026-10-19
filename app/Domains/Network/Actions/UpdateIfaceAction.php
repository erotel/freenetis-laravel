<?php

namespace App\Domains\Network\Actions;

use App\Domains\Network\Exceptions\IfaceIpRejected;
use App\Domains\Network\Repositories\IfaceRepository;
use App\Domains\Network\Support\Ip6Sync;
use App\Models\Iface;
use App\Services\AllowedSubnetSyncService;
use App\Services\PendingCustomerRedirectService;
use Illuminate\Support\Facades\DB;

/**
 * Úprava rozhraní: jméno/MAC/komentář, změna stávajících IP (+ odvozená
 * IPv6), nová IP (vlastník = člen zařízení; čekající zákazník → přepočet
 * přesměrování). Dotčené DHCP podsítě (i ty, odkud IP odešla) expirují a
 * přípojná místa člena se srovnají (podsíť bez IP odejde). Vše v jedné
 * transakci — konflikt kterékoli IP vrátí i už provedené změny.
 */
class UpdateIfaceAction
{
    public function __construct(
        private IfaceRepository $ifaces,
        private AllowedSubnetSyncService $places,
        private PendingCustomerRedirectService $pending,
    ) {
    }

    /**
     * @param array<int, array{0: ?string, 1: mixed}> $ipChanges index IP rozhraní => [nová IP, podsíť]
     * @param array{0: ?string, 1: mixed}             $newIp     [IP, podsíť] nové adresy
     * @throws IfaceIpRejected
     */
    public function handle(Iface $iface, array $values, array $ipChanges, array $newIp): void
    {
        DB::transaction(fn () => $this->apply($iface, $values, $ipChanges, $newIp));
    }

    private function apply(Iface $iface, array $values, array $ipChanges, array $newIp): void
    {
        $iface->update($values);
        $touched = [];

        foreach ($iface->ipAddresses as $n => $existing) {
            [$ip, $subnetId] = $ipChanges[$n] ?? [null, null];
            if (!$ip || !$subnetId) {
                continue;
            }
            $oldIp = $existing->ip_address;
            $oldSubnet = $existing->subnet_id;
            if ($ip !== $oldIp) {
                if ($this->ifaces->ipTaken($ip, (int) $subnetId, (int) $existing->id)) {
                    throw new IfaceIpRejected("ip_address_{$n}", "IP adresa {$ip} je již použita.");
                }
                Ip6Sync::delete($oldIp);
            }
            $existing->update(['ip_address' => $ip, 'subnet_id' => (int) $subnetId]);
            if ($ip !== $oldIp) {
                Ip6Sync::add($iface->id, $ip);
            }
            $touched[(int) $subnetId] = true;
            if ($oldSubnet && (int) $oldSubnet !== (int) $subnetId) {
                $touched[(int) $oldSubnet] = true;
            }
        }

        [$ip, $subnetId] = $newIp;
        if ($ip && $subnetId) {
            if ($this->ifaces->ipTaken($ip, (int) $subnetId)) {
                throw new IfaceIpRejected('new_ip_address', "IP adresa {$ip} je již použita.");
            }
            $memberId = $iface->device?->user?->member_id;
            $this->ifaces->addIp($iface->id, (int) $subnetId, $ip, $memberId);
            Ip6Sync::add($iface->id, $ip);
            $touched[(int) $subnetId] = true;
            if ($memberId) {
                $this->pending->refreshForMember((int) $memberId);
            }
        }

        foreach (array_keys($touched) as $sid) {
            $this->ifaces->expireDhcpSubnet($sid);
        }

        $memberId = $iface->device?->user?->member_id;
        if ($memberId) {
            foreach (array_keys($touched) as $sid) {
                $this->ifaces->hasIpInSubnet($iface, $sid)
                    ? $this->places->onIpAdded($memberId, (int) $sid)
                    : $this->places->onIpRemoved($memberId, (int) $sid);
            }
        }
    }
}
