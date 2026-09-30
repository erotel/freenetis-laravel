<?php

namespace App\Services;

use App\Models\MemberLanAccess;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Izolace klientů od vnitřní sítě (NIS2).
 *
 * Zákazník na zákaznickém segmentu routeru (DHCP subnety zařízení, vč. PPPoE —
 * PPPoE pool = tentýž subnet) smí do vnitřní sítě (lan_isolation_network,
 * výchozí 10.133.0.0/16) jen:
 *  - na veřejné služby (lan_isolation_public: DNS, FreenetIS, web),
 *  - na cíle povolené členovi ve FreenetIS (tabulka member_lan_access),
 *  - kamkoliv, je-li člen infrastrukturní (lan_isolation_infra_members — AP,
 *    switche apod. potřebují SNMP trapy, syslog, NTP…).
 * Vše ostatní ze zákaznických subnetů do vnitřní sítě se zahodí — tj. i provoz
 * klient↔klient (client isolation na AP řeší jen L2, router by jinak routoval).
 *
 * Enforcement generuje router sám z DHCP exportu (DeviceController::export),
 * jen pro zařízení v lan_isolation_devices. Router ho stahuje každých 5 min,
 * takže změna se projeví po označení dotčených subnetů jako změněných
 * (dhcp_expired / dhcp_changed_at).
 *
 * Firewall na routeru (vše s komentářem/listem „fn-lan…", ruční pravidla se
 * nemění):
 *   forward: src=fn-lan-customers dst=<síť> → jump fn-lan
 *   fn-lan:  accept established,related
 *            accept dst=fn-lan-public
 *            accept src=fn-lan-m<ID>-src dst=fn-lan-m<ID>-dst   (per člen)
 *            drop
 * Pravidla se jen doplňují (add-if-missing), address-listy se upsertují a
 * zastaralé položky mažou podle generačního razítka v komentáři → během
 * importu žádná mezera, kdy by výjimky chyběly. Match podle zdrojového subnetu
 * (ne podle rozhraní) — na zákaznickém rozhraní může téct i tranzit z dalších
 * routerů/tunelů (all-ppp zahrnuje i L2TP/SSTP), ten izolace nesmí rozbít.
 */
class LanIsolationService
{
    public const DEFAULT_NETWORK = '10.133.0.0/16';
    public const DEFAULT_PUBLIC  = "10.133.37.37\n10.133.37.38\n10.133.230.54\n10.133.230.98";

    private const PREFIX = 'fn-lan';

    /**
     * Normalizuje cíl na "a.b.c.d" (jedna IP) nebo "a.b.c.d/nn".
     *
     * @param bool $restrictToNetwork cíl musí ležet uvnitř lan_isolation_network
     *                                (mimo vnitřní síť výjimka nemá smysl)
     * @throws InvalidArgumentException s českou hláškou pro uživatele
     */
    public function normalizeDestination(string $raw, bool $restrictToNetwork = true): string
    {
        $raw = trim($raw);
        if (!preg_match('~^(\d{1,3}(?:\.\d{1,3}){3})(?:/(\d{1,2}))?$~', $raw, $m)
            || !filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new InvalidArgumentException(
                "„{$raw}“ není platná IPv4 adresa ani rozsah (např. 10.133.139.65 nebo 10.133.230.0/24)."
            );
        }
        $prefix = (isset($m[2]) && $m[2] !== '') ? (int) $m[2] : 32;
        if ($prefix > 32) {
            throw new InvalidArgumentException("„{$raw}“ má neplatnou délku prefixu (0–32).");
        }

        $ip  = ip2long($m[1]);
        $net = $ip & self::mask($prefix);
        if ($net !== $ip) {
            throw new InvalidArgumentException(
                "„{$raw}“ není adresa sítě — pro /{$prefix} je to " . long2ip($net) . "/{$prefix}."
            );
        }

        if ($restrictToNetwork) {
            [$base, $basePrefix] = $this->network();
            if ($prefix < $basePrefix || ($net & self::mask($basePrefix)) !== $base) {
                throw new InvalidArgumentException(
                    "„{$raw}“ leží mimo vnitřní síť " . $this->networkCidr() . '.'
                );
            }
        }

        return $prefix === 32 ? long2ip($net) : long2ip($net) . '/' . $prefix;
    }

    /**
     * Rozparsuje seznam cílů (řádky / čárky / mezery) z nastavení.
     *
     * @return array{0: string[], 1: string[]} [normalizované, chybové hlášky]
     */
    public function parseList(string $raw, bool $restrictToNetwork = true): array
    {
        $valid = [];
        $errors = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            try {
                $valid[] = $this->normalizeDestination($token, $restrictToNetwork);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        return [array_values(array_unique($valid)), $errors];
    }

    /** @return array{0: int, 1: int} [adresa sítě jako long, prefix] */
    public function network(): array
    {
        $raw = (string) Setting::get('lan_isolation_network', self::DEFAULT_NETWORK);
        try {
            $cidr = $this->normalizeDestination($raw !== '' ? $raw : self::DEFAULT_NETWORK, false);
        } catch (InvalidArgumentException) {
            $cidr = self::DEFAULT_NETWORK;
        }
        [$addr, $prefix] = array_pad(explode('/', $cidr, 2), 2, '32');
        return [ip2long($addr), (int) $prefix];
    }

    public function networkCidr(): string
    {
        [$base, $prefix] = $this->network();
        return long2ip($base) . '/' . $prefix;
    }

    /** @return string[] */
    public function publicDestinations(): array
    {
        // Prázdný seznam = výchozí (bez DNS by klientům nešlo nic, to nikdo nechce).
        $raw = trim((string) Setting::get('lan_isolation_public', ''));
        return $this->parseList($raw === '' ? self::DEFAULT_PUBLIC : $raw, false)[0];
    }

    /** @return int[] */
    public function infraMemberIds(): array
    {
        return self::idList((string) Setting::get('lan_isolation_infra_members', ''));
    }

    public function isInfraMember(int $memberId): bool
    {
        return in_array($memberId, $this->infraMemberIds(), true);
    }

    public function isEnabledForDevice(int $deviceId): bool
    {
        return in_array($deviceId, self::idList((string) Setting::get('lan_isolation_devices', '')), true);
    }

    /**
     * Povolené cíle per člen: výjimky z member_lan_access + celá vnitřní síť
     * pro infrastrukturní členy.
     *
     * @return array<int, string[]> member_id => [cíl, ...]
     */
    public function destinationsByMember(): array
    {
        $out = [];
        foreach (MemberLanAccess::orderBy('member_id')->orderBy('id')->get(['member_id', 'destination']) as $row) {
            $out[(int) $row->member_id][] = $row->destination;
        }
        foreach ($this->infraMemberIds() as $mid) {
            $out[$mid] = [$this->networkCidr()];
        }
        ksort($out);
        return $out;
    }

    /**
     * RouterOS sekce izolace pro DHCP export.
     *
     * @param array $servers  výstup DeviceController::buildDhcpServers (cidr, subnet_id)
     * @return string '' v relay režimu (relay MK neroutuje zákazníky), úklid
     *                „fn-lan" konfigurace pro zařízení bez zapnuté izolace,
     *                jinak plná konfigurace
     */
    public function renderMikrotik(int $deviceId, array $servers, ?string $relayInterface): string
    {
        if ($relayInterface !== null) {
            return '';
        }

        if (!$this->isEnabledForDevice($deviceId)) {
            // Úklid po případném vypnutí izolace na zařízení (no-op, když nic není).
            return '/ip firewall filter remove [/ip firewall filter find comment~"^' . self::PREFIX . '"]' . "\r\n"
                 . '/ip firewall address-list remove [/ip firewall address-list find list~"^' . self::PREFIX . '"]' . "\r\n";
        }

        $net = $this->networkCidr();
        $gen = self::PREFIX . ' ' . now()->format('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

        // Address-listy: list => [adresy]
        $lists = [
            self::PREFIX . '-customers' => array_values(array_unique(array_column($servers, 'cidr'))),
            self::PREFIX . '-public'    => $this->publicDestinations(),
        ];

        $memberDest = $this->destinationsByMember();
        $memberSrc  = $this->memberSourceIps(array_column($servers, 'subnet_id'), array_keys($memberDest));
        $members    = [];
        foreach ($memberSrc as $mid => $ips) {
            if (empty($memberDest[$mid])) {
                continue;
            }
            $members[] = $mid;
            $lists[self::PREFIX . "-m{$mid}-src"] = $ips;
            $lists[self::PREFIX . "-m{$mid}-dst"] = $memberDest[$mid];
        }

        $al  = '/ip firewall address-list';
        $fw  = '/ip firewall filter';
        $out = "# izolace klientu od vnitrni site {$net} (FreenetIS)\r\n";

        foreach ($lists as $list => $addresses) {
            foreach ($addresses as $a) {
                $find = "[{$al} find list=\"{$list}\" address=\"{$a}\"]";
                $out .= ":if ([:len {$find}]=0)"
                      . " do={{$al} add list=\"{$list}\" address={$a} comment=\"{$gen}\"}"
                      . " else={{$al} set {$find} comment=\"{$gen}\"}\r\n";
            }
        }
        $out .= "{$al} remove [{$al} find list~\"^" . self::PREFIX . "\" comment!=\"{$gen}\"]\r\n";

        // Chain fn-lan: drop na konec, ostatní accept před něj.
        $drop   = "[{$fw} find comment=\"" . self::PREFIX . ":drop\"]";
        $accept = [
            self::PREFIX . ':est'    => 'connection-state=established,related',
            self::PREFIX . ':public' => 'dst-address-list=' . self::PREFIX . '-public',
        ];
        foreach ($members as $mid) {
            $accept[self::PREFIX . ":m{$mid}"] = 'src-address-list=' . self::PREFIX . "-m{$mid}-src"
                                               . ' dst-address-list=' . self::PREFIX . "-m{$mid}-dst";
        }

        $out .= ":if ([:len {$drop}]=0) do={{$fw} add chain=" . self::PREFIX . ' action=drop comment="' . self::PREFIX . ":drop\"}\r\n";
        foreach ($accept as $comment => $match) {
            $out .= ":if ([:len [{$fw} find comment=\"{$comment}\"]]=0)"
                  . " do={{$fw} add chain=" . self::PREFIX . " {$match} action=accept comment=\"{$comment}\" place-before={$drop}}\r\n";
        }
        // Pravidla členů, kteří už výjimku (nebo IP na tomhle routeru) nemají.
        $keep = implode('', array_map(fn ($mid) => ' comment!="' . self::PREFIX . ":m{$mid}\"", $members));
        $out .= "{$fw} remove [{$fw} find chain=\"" . self::PREFIX . '" comment~"^' . self::PREFIX . ":m\"{$keep}]\r\n";

        // Jump z forward až nakonec (aktivuje izolaci, když je chain hotový);
        // na začátek forward, před ruční pravidla. Síť se při změně nastavení přepíše.
        $jump      = "[{$fw} find comment=\"" . self::PREFIX . ':jump"]';
        $jumpMatch = 'chain=forward src-address-list=' . self::PREFIX . "-customers dst-address={$net}"
                   . ' action=jump jump-target=' . self::PREFIX . ' comment="' . self::PREFIX . ':jump"';
        $out .= ":if ([:len {$jump}]=0) do={"
              . ":if ([:len [{$fw} find dynamic=no]]>0)"
              . " do={{$fw} add {$jumpMatch} place-before=[:pick [{$fw} find dynamic=no] 0]}"
              . " else={{$fw} add {$jumpMatch}}"
              . "} else={{$fw} set {$jump} dst-address={$net}}\r\n";

        return $out;
    }

    /**
     * IP adresy členů v daných subnetech (IP na rozhraních jejich zařízení
     * i IP přiřazené přímo členovi). Brána subnetu (IP routeru) se vynechá.
     *
     * @return array<int, string[]> member_id => [ip, ...]
     */
    public function memberSourceIps(array $subnetIds, array $memberIds): array
    {
        $subnetIds = array_values(array_filter(array_map('intval', $subnetIds)));
        $memberIds = array_values(array_filter(array_map('intval', $memberIds)));
        if (!$subnetIds || !$memberIds) {
            return [];
        }

        $rows = DB::table('ip_addresses as ip')
            ->leftJoin('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->leftJoin('devices as d', 'd.id', '=', 'i.device_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->whereIn('ip.subnet_id', $subnetIds)
            ->where(fn ($q) => $q->where('ip.gateway', 0)->orWhereNull('ip.gateway'))
            ->where(fn ($q) => $q->whereIn('u.member_id', $memberIds)->orWhereIn('ip.member_id', $memberIds))
            ->orderByRaw('INET_ATON(ip.ip_address)')
            ->get(['ip.ip_address', 'u.member_id as dev_member', 'ip.member_id as ip_member']);

        $out = [];
        foreach ($rows as $r) {
            $mid = (int) ($r->dev_member ?? $r->ip_member);
            if ($mid && filter_var($r->ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $out[$mid][] = $r->ip_address;
            }
        }
        foreach ($out as $mid => $ips) {
            $out[$mid] = array_values(array_unique($ips));
        }
        ksort($out);
        return $out;
    }

    /**
     * Označí DHCP subnety, kde má člen IP, jako změněné → routery si při dalším
     * stažení exportu vezmou novou konfiguraci izolace.
     */
    public function expireSubnetsForMember(int $memberId): int
    {
        $viaDevices = DB::table('ip_addresses as ip')
            ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('u.member_id', $memberId)
            ->whereNotNull('ip.subnet_id')
            ->pluck('ip.subnet_id');
        $direct = DB::table('ip_addresses')->where('member_id', $memberId)->whereNotNull('subnet_id')->pluck('subnet_id');

        return $this->expire($viaDevices->merge($direct)->unique()->values()->all());
    }

    /** Změna globálního nastavení izolace → přegenerovat export všem. */
    public function expireAllDhcpSubnets(): int
    {
        return $this->expire(null);
    }

    private function expire(?array $subnetIds): int
    {
        if ($subnetIds !== null && !$subnetIds) {
            return 0;
        }
        $q = DB::table('subnets')->where('dhcp', 1);
        if ($subnetIds !== null) {
            $q->whereIn('id', $subnetIds);
        }
        // Mikrosekundy kvůli per-client detekci změn (viz Subnet::$dateFormat).
        return $q->update(['dhcp_expired' => 1, 'dhcp_changed_at' => now()->format('Y-m-d H:i:s.u')]);
    }

    private static function mask(int $prefix): int
    {
        return $prefix === 0 ? 0 : ((~0) << (32 - $prefix)) & 0xFFFFFFFF;
    }

    /** @return int[] */
    private static function idList(string $raw): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY))
        )));
    }
}
