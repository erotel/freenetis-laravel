<?php

namespace App\Domains\Access\Repositories;

use App\Domains\Network\Support\SubnetDhcpChanges;
use App\Models\ConnectionRequest;
use App\Models\Device;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data pro DHCP export MikroTiků: zařízení s rozhraními → IP → subnety, stav
 * synchronizace (legacy `subnets.dhcp_expired` / per-client `dhcp_export_state`).
 *
 * KONTRAKT: MK stahují export každých 5 min (dhcp_api_token). Detekce změn:
 *  - bez ?client: sdílený boolean `subnets.dhcp_expired` (jeden konzument),
 *  - s ?client:   `subnets.dhcp_changed_at` vs. `dhcp_export_state.exported_at`
 *                 (high-water-mark per konzument, mikrosekundy).
 * Změny nastavuje Network\Support\SubnetDhcpChanges::expire() (dhcp_expired=1 + dhcp_changed_at=now).
 */
class DhcpExportRepository
{
    public function deviceWithSubnets(int $deviceId): ?Device
    {
        return Device::with(['ifaces.ipAddresses.subnet'])->find($deviceId);
    }

    public function touchAccessTime(Device $device): void
    {
        $device->update(['access_time' => now()]);
    }

    /**
     * IP čekajících žádostí o připojení (state=UNDECIDED) jako long.
     *
     * @return int[]
     */
    public function pendingRequestIpLongs(): array
    {
        return DB::table('connection_requests')
            ->where('state', ConnectionRequest::STATE_UNDECIDED)
            ->whereNotNull('ip_address')
            ->pluck('ip_address')
            ->map(fn ($ip) => ip2long($ip))
            ->filter(fn ($l) => $l !== false)
            ->all();
    }

    public function clientExportedAt(string $client): ?Carbon
    {
        $raw = DB::table('dhcp_export_state')->where('client', $client)->value('exported_at');
        return $raw ? Carbon::parse($raw) : null;
    }

    public function markClientExported(string $client, Carbon $at): void
    {
        DB::table('dhcp_export_state')->updateOrInsert(
            ['client' => $client],
            // formát s .u — query builder jinak Carbon uloží jen s vteřinovou
            // přesností a mikrosekundová detekce by ztratila smysl.
            ['exported_at' => $at->format('Y-m-d H:i:s.u')]
        );
    }

    /** Nejnovější dhcp_changed_at přes DHCP subnety IP adres zařízení. */
    public function latestSubnetChange(Device $device): ?Carbon
    {
        $latest = null;
        foreach ($this->dhcpSubnets($device) as $subnet) {
            $ts = $subnet->dhcp_changed_at;
            if ($ts && ($latest === null || $ts->gt($latest))) {
                $latest = $ts;
            }
        }
        return $latest;
    }

    public function hasExpiredSubnet(Device $device): bool
    {
        foreach ($this->dhcpSubnets($device) as $subnet) {
            if ($subnet->dhcp_expired) {
                return true;
            }
        }
        return false;
    }

    public function markSubnetsSynced(Device $device): void
    {
        foreach ($this->dhcpSubnets($device) as $subnet) {
            SubnetDhcpChanges::synced($subnet);
        }
    }

    /**
     * DHCP subnety všech IP zařízení (bez ohledu na gateway — tak to detekce
     * dělá odjakživa). Opakovaný subnet se vrací víckrát, jako v původní smyčce.
     *
     * @return iterable<\App\Models\Subnet>
     */
    private function dhcpSubnets(Device $device): iterable
    {
        foreach ($device->ifaces as $iface) {
            foreach ($iface->ipAddresses as $ip) {
                if ($ip->subnet && $ip->subnet->dhcp) {
                    yield $ip->subnet;
                }
            }
        }
    }
}
