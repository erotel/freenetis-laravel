<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Machine-to-machine HTTP API for network gateways, routers, and access points.
 * Mirrors Kohana's Web_interface_Controller. Access is restricted by IP ranges
 * configured in Settings (address_ranges), not by user authentication.
 */
class WebInterfaceController extends Controller
{
    // Association member is always excluded from QoS/IP exports
    private const ASSOCIATION_MEMBER_ID = 1;

    // ── Access guards ─────────────────────────────────────────────────────────

    private function isFromTrustedRange(): bool
    {
        $ranges  = Setting::get('address_ranges', '');
        $clientIp = request()->ip();
        foreach (explode(',', $ranges) as $cidr) {
            $cidr = trim($cidr);
            if ($cidr !== '' && $this->ipInCidr($clientIp, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = $bits >= 32 ? 0xFFFFFFFF : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private function guardTrusted(): void
    {
        if (!$this->isFromTrustedRange()) {
            abort(403);
        }
    }

    private function guardRedirection(): void
    {
        if (Setting::get('redirection_enabled', '0') != '1' || !$this->isFromTrustedRange()) {
            abort(403);
        }
    }

    private function textResponse(array $lines): Response
    {
        return response(implode("\n", $lines) . "\n")
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    // ── 1. redirected_ranges ─────────────────────────────────────────────────

    public function redirectedRanges(): Response
    {
        $this->guardRedirection();

        $rows = DB::select("
            SELECT DISTINCT CONCAT(
                network_address, '/',
                32 - log2((~inet_aton(netmask) & 0xffffffff) + 1)
            ) AS subnet_range
            FROM subnets
            WHERE redirect = 1
            ORDER BY INET_ATON(network_address)
        ");

        Setting::set('redirection_state', now()->format('Y-m-d H:i:s'));

        return $this->textResponse(array_column($rows, 'subnet_range'));
    }

    // ── 2. allowed_ip_addresses ──────────────────────────────────────────────

    public function allowedIpAddresses(): Response
    {
        $this->guardRedirection();

        $rows = DB::select("
            SELECT DISTINCT ip.ip_address
            FROM ip_addresses ip
            LEFT JOIN messages_ip_addresses mip ON mip.ip_address_id = ip.id
            WHERE mip.ip_address_id IS NULL
        ");

        Setting::set('redirection_state', now()->format('Y-m-d H:i:s'));

        return $this->textResponse(array_column($rows, 'ip_address'));
    }

    // ── 3. unallowed_ip_addresses ────────────────────────────────────────────

    public function unallowedIpAddresses(?int $messageType = null): Response
    {
        $this->guardRedirection();

        if ($messageType !== null && $messageType >= 0) {
            $rows = DB::select("
                SELECT DISTINCT ip.ip_address
                FROM ip_addresses ip
                JOIN messages_ip_addresses mip ON mip.ip_address_id = ip.id
                JOIN messages m ON m.id = mip.message_id
                WHERE m.type = ?
            ", [$messageType]);
        } else {
            $rows = DB::select("
                SELECT DISTINCT ip.ip_address
                FROM ip_addresses ip
                JOIN messages_ip_addresses mip ON mip.ip_address_id = ip.id
            ");
        }

        Setting::set('redirection_state', now()->format('Y-m-d H:i:s'));

        return $this->textResponse(array_column($rows, 'ip_address'));
    }

    // ── 4. self_cancelable_ip_addresses ──────────────────────────────────────

    public function selfCancelableIpAddresses(): Response
    {
        $this->guardRedirection();

        // Vrátí jen IP, jejichž nejmenší self_cancel hodnota (přes všechny napojené
        // messages) je > 0. Tj. všechny zprávy které danou IP odkazují, jsou self-cancelable.
        // Původní Kohana query stavěla na starém MySQL chování "ORDER BY uvnitř subselectu
        // + GROUP BY vrátí první řádek" — to MariaDB s ONLY_FULL_GROUP_BY odmítne.
        // Přepsáno na MIN() s explicit GROUP BY (kompatibilní s ONLY_FULL_GROUP_BY).
        $rows = DB::select("
            SELECT ip.ip_address
            FROM ip_addresses ip
            JOIN messages_ip_addresses mip ON mip.ip_address_id = ip.id
            JOIN messages m ON m.id = mip.message_id
            GROUP BY ip.id, ip.ip_address
            HAVING MIN(IFNULL(m.self_cancel, 0)) > 0
        ");

        Setting::set('redirection_state', now()->format('Y-m-d H:i:s'));

        return $this->textResponse(array_column($rows, 'ip_address'));
    }

    // ── 5. allowed_ip6_addresses ─────────────────────────────────────────────

    public function allowedIp6Addresses(): Response
    {
        $this->guardRedirection();

        $rows = DB::select("
            SELECT DISTINCT ip.ip_address
            FROM ip_addresses ip
            LEFT JOIN messages_ip_addresses mip ON mip.ip_address_id = ip.id
            WHERE mip.ip_address_id IS NULL
        ");

        // Setting::get vrátí default jen pokud řádek neexistuje — pokud je hodnota
        // v DB prázdná (legacy import bez vyplnění), fallback by se neuplatnil
        // a výstup by byl ":Y:Z00::/" bez prefixu a masky. ?: ošetří i prázdné string.
        $prefix = Setting::get('ipv6_prefix') ?: '2a07:9c0';
        $mask   = Setting::get('ipv6_mask')   ?: '56';

        $items = [];
        foreach ($rows as $row) {
            $parts = explode('.', $row->ip_address);
            if (count($parts) !== 4 || $parts[0] !== '10') {
                continue;
            }
            $y       = dechex((int) $parts[2]);
            $z       = dechex((int) $parts[3]);
            $items[] = "{$prefix}:{$y}:{$z}00::/{$mask}";
        }

        return $this->textResponse($items);
    }

    // ── 6. ipv6_radius ───────────────────────────────────────────────────────

    public function ipv6Radius(): Response
    {
        $this->guardTrusted();

        $rows = DB::select("
            SELECT ip.ip_address, i.mac AS mac
            FROM ip6_addresses ip
            JOIN ifaces i ON i.id = ip.iface_id
            WHERE i.mac IS NOT NULL
            ORDER BY i.mac ASC
        ");

        $lines = array_map(fn($r) => $r->ip_address . ',' . $r->mac, $rows);

        return $this->textResponse($lines);
    }

    // ── 7. qos_json ──────────────────────────────────────────────────────────

    public function qosJson()
    {
        $this->guardTrusted();

        $speedClasses = DB::select('SELECT * FROM speed_classes ORDER BY d_ceil DESC');

        $profiles = [];
        foreach ($speedClasses as $sc) {
            $profiles[] = [
                'id'             => (int) $sc->id,
                'name'           => (string) $sc->name,
                'up_kbit'        => (int) ($sc->u_rate  / 1000000 * 1024),
                'down_kbit'      => (int) ($sc->d_rate  / 1000000 * 1024),
                'up_ceil_kbit'   => (int) ($sc->u_ceil  / 1000000 * 1024),
                'down_ceil_kbit' => (int) ($sc->d_ceil  / 1000000 * 1024),
            ];
        }

        // Rychlost člena (fallback) a rychlost per přípojné místo (povolená podsíť).
        $memberSpeed = DB::table('members')->pluck('speed_class_id', 'id'); // id => speed_class_id|null
        $placeSpeed  = []; // "member-subnet" => speed_class_id
        foreach (DB::table('allowed_subnets')->whereNotNull('speed_class_id')->get(['member_id', 'subnet_id', 'speed_class_id']) as $a) {
            $placeSpeed[$a->member_id . '-' . $a->subnet_id] = (int) $a->speed_class_id;
        }

        // IPv6 /56 se odvozuje z IPv4 (10.x.B.C → prefix:hex(B):hex(C)00::/mask),
        // stejně jako v allowedIp6Addresses. Díky tomu IPv6 sdílí přípojné místo
        // (a tedy rychlost) své IPv4 — netahá se z ip6_addresses samostatně.
        $v6prefix = Setting::get('ipv6_prefix') ?: '2a07:9c0';
        $v6mask   = Setting::get('ipv6_mask')   ?: '56';

        // Efektivní profil každé IP: rychlost její podsítě (allowed_subnets), jinak
        // rychlost člena. member->profile_id + ploché ipv4/ipv6 zůstávají pro zpětnou
        // kompatibilitu (starý gateway aplikuje profil člena na všechny jeho IP);
        // assignments[] je nové – profil per IP (+ odvozená IPv6 ve stejné skupině).
        $members = [];
        $seen    = []; // dedup podle ip_address (jeden vlastník na IP)

        $rows = DB::select("
            SELECT ip.ip_address, ip.subnet_id,
                   COALESCE(ip.member_id, u.member_id) AS member_id, ip.id AS ip_id
            FROM ip_addresses ip
            LEFT JOIN ifaces i  ON i.id = ip.iface_id
            LEFT JOIN devices d ON d.id = i.device_id
            LEFT JOIN users u   ON u.id = d.user_id
            WHERE COALESCE(ip.member_id, u.member_id) IS NOT NULL
              AND COALESCE(ip.member_id, u.member_id) <> ?
            ORDER BY member_id, ip.id
        ", [self::ASSOCIATION_MEMBER_ID]);

        foreach ($rows as $r) {
            $ipStr = (string) $r->ip_address;
            if ($ipStr === '' || isset($seen[$ipStr])) {
                continue;
            }
            $mid = (int) $r->member_id;

            $profile = $placeSpeed[$mid . '-' . $r->subnet_id]
                ?? (isset($memberSpeed[$mid]) ? (int) $memberSpeed[$mid] : null);
            if ($profile === null) {
                continue; // člen ani místo nemají rychlost → do QoS nepatří
            }
            $seen[$ipStr] = true;

            if (!isset($members[$mid])) {
                $members[$mid] = [
                    'member_id'   => $mid,
                    'profile_id'  => isset($memberSpeed[$mid]) ? (int) $memberSpeed[$mid] : $profile,
                    'ipv4'        => [],
                    'ipv6'        => [],
                    'assignments' => [],
                ];
            }
            if (!isset($members[$mid]['assignments'][$profile])) {
                $members[$mid]['assignments'][$profile] = ['profile_id' => $profile, 'ipv4' => [], 'ipv6' => []];
            }

            $members[$mid]['ipv4'][] = $ipStr;
            $members[$mid]['assignments'][$profile]['ipv4'][] = $ipStr;

            // Odvozená IPv6 /56 (jen z 10.x adres) → do stejné rychlostní skupiny.
            $v6 = $this->ipv4ToIpv6Prefix($ipStr, $v6prefix, $v6mask);
            if ($v6 !== null) {
                $members[$mid]['ipv6'][] = $v6;
                $members[$mid]['assignments'][$profile]['ipv6'][] = $v6;
            }
        }

        // dedup IPv6 (víc IPv4 může mapovat na stejnou /56) a assignments na seznam
        $members = array_map(function ($m) {
            $m['ipv6'] = array_values(array_unique($m['ipv6']));
            $m['assignments'] = array_values(array_map(function ($a) {
                $a['ipv6'] = array_values(array_unique($a['ipv6']));
                return $a;
            }, $m['assignments']));
            return $m;
        }, array_values($members));

        return response()->json([
            'generated_at' => gmdate('c'),
            'profiles'     => $profiles,
            'members'      => $members,
        ]);
    }

    /**
     * Odvodí IPv6 /56 prefix z IPv4 adresy: 10.x.B.C → "{prefix}:hex(B):hex(C)00::/{mask}".
     * Jen pro adresy 10.x (ostatní, např. veřejné, IPv6 /56 nemají). Shodné se
     * schématem v allowedIp6Addresses.
     */
    private function ipv4ToIpv6Prefix(string $ipv4, string $prefix, string $mask): ?string
    {
        $parts = explode('.', $ipv4);
        if (count($parts) !== 4 || $parts[0] !== '10') {
            return null;
        }
        $y = dechex((int) $parts[2]);
        $z = dechex((int) $parts[3]);

        return "{$prefix}:{$y}:{$z}00::/{$mask}";
    }

    // ── 8. public_port_forwards_json ─────────────────────────────────────────

    public function publicPortForwardsJson()
    {
        $this->guardTrusted();

        $rows = DB::select("
            SELECT id, public_ip, public_port_from, public_port_to,
                   private_ip, private_port_from, private_port_to, protocol
            FROM public_port_forwards
            WHERE enabled = 1
            ORDER BY public_ip, protocol, public_port_from, public_port_to, private_ip
        ");

        return response()->json([
            'generated_at'         => gmdate('c'),
            'public_port_forwards' => $rows,
        ]);
    }

    // ── 9. public_ip_nat_1to1_json ───────────────────────────────────────────

    public function publicIpNat1to1Json()
    {
        $this->guardTrusted();

        $rows = DB::select("
            SELECT id, public_ip, private_ip, scope, comment
            FROM public_ip_nat_1to1
            WHERE enabled = 1 AND private_ip IS NOT NULL AND private_ip <> ''
            ORDER BY scope, public_ip
        ");

        return response()->json([
            'generated_at'       => gmdate('c'),
            'public_ip_nat_1to1' => $rows,
        ]);
    }

    // ── 10. public_port_forwards_txt ─────────────────────────────────────────

    public function publicPortForwardsTxt(): Response
    {
        $this->guardTrusted();

        $rows = DB::select("
            SELECT protocol, public_ip, public_port_from, public_port_to,
                   private_ip, private_port_from, private_port_to
            FROM public_port_forwards
            WHERE enabled = 1
              AND (public_port_to - public_port_from) = (private_port_to - private_port_from)
              AND public_port_from <= public_port_to
              AND private_port_from <= private_port_to
            ORDER BY public_ip, protocol, public_port_from, public_port_to, private_ip
        ");

        $lines = [];
        foreach ($rows as $r) {
            $pubFrom  = (int) $r->public_port_from;
            $pubTo    = (int) $r->public_port_to;
            $lanFrom  = (int) $r->private_port_from;
            $lanTo    = (int) $r->private_port_to;
            $pubPorts = $pubFrom === $pubTo ? (string) $pubFrom : "{$pubFrom}:{$pubTo}";
            $lanPorts = $lanFrom === $lanTo ? (string) $lanFrom : "{$lanFrom}-{$lanTo}";
            $lines[]  = strtolower($r->protocol) . ';' . $r->public_ip . ';' . $pubPorts . ';' . $r->private_ip . ';' . $lanPorts;
        }

        return $this->textResponse($lines);
    }

    // ── 11. public_ip_nat_1to1_txt ───────────────────────────────────────────

    public function publicIpNat1to1Txt(Request $request): Response
    {
        $this->guardTrusted();

        $scope = trim((string) $request->query('scope', ''));
        if ($scope !== '' && !preg_match('~^[A-Za-z0-9_.:-]{1,64}$~', $scope)) {
            abort(400);
        }

        if ($scope !== '') {
            $rows = DB::select("
                SELECT public_ip, private_ip, comment
                FROM public_ip_nat_1to1
                WHERE enabled = 1 AND scope = ? AND private_ip IS NOT NULL AND private_ip <> ''
                ORDER BY public_ip
            ", [$scope]);
        } else {
            $rows = DB::select("
                SELECT public_ip, private_ip, comment
                FROM public_ip_nat_1to1
                WHERE enabled = 1 AND private_ip IS NOT NULL AND private_ip <> ''
                ORDER BY scope, public_ip
            ");
        }

        $lines = [];
        foreach ($rows as $r) {
            if (!empty($r->comment)) {
                $lines[] = '# ' . str_replace(["\r", "\n"], ' ', (string) $r->comment);
            }
            $lines[] = $r->public_ip . ';' . $r->private_ip;
        }

        return $this->textResponse($lines);
    }

    // ── 12. smtp_exceptions_json ─────────────────────────────────────────────

    public function smtpExceptionsJson()
    {
        $this->guardTrusted();

        $rows = DB::select("
            SELECT id, intip, user, datum
            FROM smtp_exceptions
            ORDER BY INET_ATON(intip)
        ");

        return response()->json([
            'generated_at'    => gmdate('c'),
            'smtp_exceptions' => $rows,
        ]);
    }

    // ── 13. smtp_exceptions_txt ──────────────────────────────────────────────

    public function smtpExceptionsTxt(): Response
    {
        $this->guardTrusted();

        $rows = DB::select("
            SELECT intip
            FROM smtp_exceptions
            ORDER BY INET_ATON(intip)
        ");

        return $this->textResponse(array_column($rows, 'intip'));
    }
}
