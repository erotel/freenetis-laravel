<?php

namespace App\Domains\Access\Dhcp;

use App\Domains\Access\Repositories\DhcpExportRepository;
use App\Models\Device;
use App\Models\Setting;

/**
 * Poskládá DHCP servery zařízení (jeden na každou bránu v DHCP subnetu) jako
 * pole pro renderer: pool (fragmentovaný o registrované statiky a čekající
 * žádosti), DNS, statické leasy hostů, ipoe flag.
 *
 * Výstup čte i LanIsolationService::renderMikrotik (cidr, subnet_id).
 */
class DhcpServerBuilder
{
    public function __construct(private DhcpExportRepository $repo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(Device $device): array
    {
        // Admin v UI typicky odděluje DNS servery Enterem (LF/CRLF), ale legacy
        // data z Kohana migrace občas obsahují literální `\n` (backslash-n).
        // Sjednotíme separátor a po splitu validujeme IPv4 — kdyby v hodnotě
        // bylo cokoliv jiného, do Mikrotik exportu prosakovala garbage jako
        // `dns-server=10.133.37.3710.133.37.38` (chybějící čárka, nebo dvojice
        // IP slepená do sebe).
        $dnsRaw     = (string) Setting::get('dns_servers', '');
        $dnsRaw     = str_replace('\\n', "\n", $dnsRaw);
        $dnsServers = array_values(array_filter(
            array_map('trim', preg_split('/[,;\s|]+/', $dnsRaw)),
            fn($v) => $v !== '' && filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ));

        $servers = [];

        // IP čekajících žádostí o připojení (state=UNDECIDED) — se zapnutým PPPoE
        // je RADIUS servíruje jako Framed-IP z žádosti UŽ PŘED schválením, takže
        // je musí pool vyříznout stejně jako registrované statiky (jinak by je
        // přidělil dalšímu bootstrap klientovi = konflikt). long2ip klíče.
        $pendingReqLongs = Setting::get('pppoe_enabled', 0)
            ? $this->repo->pendingRequestIpLongs()
            : [];

        foreach ($device->ifaces as $iface) {
            foreach ($iface->ipAddresses as $ip) {
                \Log::debug('DHCP export ip check', [
                    'ip'      => $ip->ip_address,
                    'gateway' => $ip->gateway,
                    'subnet'  => $ip->subnet?->network_address,
                    'dhcp'    => $ip->subnet?->dhcp,
                ]);
                if (!$ip->gateway || !$ip->subnet) continue;
                $subnet = $ip->subnet;
                if (!$subnet->dhcp) continue;

                $network   = ip2long($subnet->network_address);
                $mask      = ip2long($subnet->netmask);
                $broadcast = long2ip($network | (~$mask & 0xFFFFFFFF));
                $cidrBits  = 32 - (int) log(~$mask & 0xFFFFFFFF, 2) - 1;
                // fix: count bits properly
                $cidrBits  = substr_count(sprintf('%032b', $mask & 0xFFFFFFFF), '1');
                // Fragmentovaný pool = univerzum (gateway+1 .. broadcast-1) MÍNUS
                // všechny FreenetIS-alokované IP (iface_id) v subnetu. Důvod: PPPoE
                // `/ip pool` nezná statické DHCP leasy ani RADIUS Framed-IP → kdyby
                // registrovaná statika zůstala v ranges, PPPoE bootstrap (admin) by
                // ji přidělil znovu = adresní/routing konflikt na BRASu. Pro DHCP je
                // fragmentace ekvivalentní (statiku i tak chrání MAC lease), takže
                // jeden sdílený pool obslouží DHCP i PPPoE. Viz onboarding návrh.
                $poolStart = ip2long($ip->ip_address) + 1;
                $poolEnd   = ip2long($broadcast) - 1;
                $excluded  = [];
                foreach ($subnet->ipAddresses as $alloc) {
                    if (!$alloc->iface_id) continue;
                    $l = ip2long($alloc->ip_address);
                    if ($l !== false && $l >= $poolStart && $l <= $poolEnd) {
                        $excluded[$l] = true;
                    }
                }
                // + IP čekajících žádostí spadající do tohoto poolu (viz výše).
                foreach ($pendingReqLongs as $l) {
                    if ($l >= $poolStart && $l <= $poolEnd) {
                        $excluded[$l] = true;
                    }
                }
                $ranges     = $this->fragmentPoolRanges($poolStart, $poolEnd, array_keys($excluded));
                $rangeStart = long2ip($poolStart);
                $rangeEnd   = long2ip($poolEnd);

                $dnsList = $dnsServers;
                if ($subnet->dns ?? false) {
                    array_unshift($dnsList, $ip->ip_address);
                }

                $hosts = [];
                foreach ($subnet->ipAddresses as $hostIp) {
                    if ($hostIp->ip_address === $ip->ip_address || !$hostIp->iface_id) continue;
                    $mac = $hostIp->iface?->mac ?? '';
                    if (!$mac || !preg_match('/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $mac)) continue;
                    $deviceName  = $hostIp->iface?->device?->name ?? '';
                    $memberId    = $hostIp->iface?->device?->user?->member_id ?? '';
                    $memberName  = $hostIp->iface?->device?->user?->member?->name ?? '';
                    $hosts[$mac] = [
                        'ip_address' => $hostIp->ip_address,
                        'mac'        => $mac,
                        'server'     => MikrotikDhcpRenderer::ascii($subnet->name),
                        'comment'    => "ID {$memberId} - {$memberName} - {$deviceName}",
                    ];
                }
                ksort($hosts);

                $servers[] = [
                    'subnet_id'   => (int) $subnet->id,
                    'name'        => MikrotikDhcpRenderer::ascii($subnet->name),
                    'cidr'        => $subnet->network_address . '/' . $cidrBits,
                    'network'     => $subnet->network_address,
                    'netmask'     => $subnet->netmask,
                    'gateway'     => $ip->ip_address,
                    'interface'   => $iface->name,
                    'range_start' => $rangeStart,
                    'range_end'   => $rangeEnd,
                    'ranges'      => $ranges,
                    'dns_servers' => $dnsList,
                    'hosts'       => array_values($hosts),
                    // IPoE (line-id) subnet: MK se ptá RADIUSu (use-radius) a
                    // statické leasy se NEgenerují → IP jede z RADIUS Framed-IP
                    // podle option82 circuit-id. Viz fáze B.
                    'ipoe'        => (bool) $subnet->ipoe,
                ];
            }
        }
        return $servers;
    }

    /**
     * Poskládá pool ranges z intervalu [$startLong, $endLong] s vyříznutými
     * adresami $excludedLongs (fragmentovaný pool = subnet minus registrované
     * statiky). Vrací pole "start-end" řetězců — pro RouterOS `ranges=a-b,c-d`.
     * Prázdné pole = celý interval je alokovaný (žádná volná adresa).
     */
    public function fragmentPoolRanges(int $startLong, int $endLong, array $excludedLongs): array
    {
        if ($startLong > $endLong) {
            return [];
        }
        $ex = array_filter($excludedLongs, fn ($l) => $l >= $startLong && $l <= $endLong);
        $ex = array_values(array_unique($ex));
        sort($ex);

        $ranges = [];
        $cur = $startLong;
        foreach ($ex as $e) {
            if ($e > $cur) {
                $ranges[] = long2ip($cur) . '-' . long2ip($e - 1);
            }
            $cur = $e + 1;
        }
        if ($cur <= $endLong) {
            $ranges[] = long2ip($cur) . '-' . long2ip($endLong);
        }
        return $ranges;
    }
}
