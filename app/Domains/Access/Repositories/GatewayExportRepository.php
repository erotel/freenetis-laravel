<?php

namespace App\Domains\Access\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * Data M2M exportů pro brány (přesměrování, IPv6, QoS, port forwardy, NAT,
 * SMTP výjimky). Dotazy převzaté doslova z Kohana Web_interface_Controller —
 * výstup brány parsují, pořadí a DISTINCT se nesmí měnit.
 */
class GatewayExportRepository
{
    /** CIDR podsítí s redirect=1. @return string[] */
    public function redirectedRanges(): array
    {
        return array_column(DB::select("
            SELECT DISTINCT CONCAT(network_address, '/', 32 - log2((~inet_aton(netmask) & 0xffffffff) + 1)) AS subnet_range
            FROM subnets
            WHERE redirect = 1
            ORDER BY INET_ATON(network_address)
        "), 'subnet_range');
    }

    /** IP bez přesměrování. @return string[] */
    public function allowedIps(): array
    {
        return array_column(DB::select("
            SELECT DISTINCT ip.ip_address
            FROM ip_addresses ip
            LEFT JOIN messages_ip_addresses mip ON mip.ip_address_id = ip.id
            WHERE mip.ip_address_id IS NULL
        "), 'ip_address');
    }

    /** Přesměrované IP (volitelně jen zprávy daného typu). @return string[] */
    public function redirectedIps(?int $messageType): array
    {
        $rows = $messageType !== null && $messageType >= 0
            ? DB::select("
                SELECT DISTINCT ip.ip_address
                FROM ip_addresses ip
                JOIN messages_ip_addresses mip ON mip.ip_address_id = ip.id
                JOIN messages m ON m.id = mip.message_id
                WHERE m.type = ?
            ", [$messageType])
            : DB::select("
                SELECT DISTINCT ip.ip_address
                FROM ip_addresses ip
                JOIN messages_ip_addresses mip ON mip.ip_address_id = ip.id
            ");
        return array_column($rows, 'ip_address');
    }

    /**
     * IP, jejichž VŠECHNY zprávy jsou self-cancelable (MIN(self_cancel) > 0).
     * Kohana stavěla na ORDER BY v subselectu + GROUP BY (první řádek) — to
     * ONLY_FULL_GROUP_BY odmítne, proto MIN s explicitním GROUP BY.
     * @return string[]
     */
    public function selfCancelableIps(): array
    {
        return array_column(DB::select("
            SELECT ip.ip_address
            FROM ip_addresses ip
            JOIN messages_ip_addresses mip ON mip.ip_address_id = ip.id
            JOIN messages m ON m.id = mip.message_id
            GROUP BY ip.id, ip.ip_address
            HAVING MIN(IFNULL(m.self_cancel, 0)) > 0
        "), 'ip_address');
    }

    /** IPv6 rozhraní s MAC pro RADIUS: „ip6,mac" řazeno podle MAC. @return string[] */
    public function ipv6WithMac(): array
    {
        return array_map(fn ($r) => $r->ip_address . ',' . $r->mac, DB::select("
            SELECT ip.ip_address, i.mac AS mac
            FROM ip6_addresses ip
            JOIN ifaces i ON i.id = ip.iface_id
            WHERE i.mac IS NOT NULL
            ORDER BY i.mac ASC
        "));
    }

    public function speedClasses(): array
    {
        return DB::select('SELECT * FROM speed_classes ORDER BY d_ceil DESC');
    }

    /** member_id => speed_class_id|null */
    public function memberSpeeds()
    {
        return DB::table('members')->pluck('speed_class_id', 'id');
    }

    /** „member-subnet" => speed_class_id povolených podsítí s vlastní rychlostí. */
    public function placeSpeeds(): array
    {
        $out = [];
        foreach (DB::table('allowed_subnets')->whereNotNull('speed_class_id')->get(['member_id', 'subnet_id', 'speed_class_id']) as $a) {
            $out[$a->member_id . '-' . $a->subnet_id] = (int) $a->speed_class_id;
        }
        return $out;
    }

    /** IPv4 s vlastníkem (přímo nebo přes zařízení), kromě sdružení, podle člena a id IP. */
    public function ownedIps(int $associationId): array
    {
        return DB::select("
            SELECT ip.ip_address, ip.subnet_id,
                   COALESCE(ip.member_id, u.member_id) AS member_id, ip.id AS ip_id
            FROM ip_addresses ip
            LEFT JOIN ifaces i  ON i.id = ip.iface_id
            LEFT JOIN devices d ON d.id = i.device_id
            LEFT JOIN users u   ON u.id = d.user_id
            WHERE COALESCE(ip.member_id, u.member_id) IS NOT NULL
              AND COALESCE(ip.member_id, u.member_id) <> ?
            ORDER BY member_id, ip.id
        ", [$associationId]);
    }

    public function portForwards(): array
    {
        return DB::select("
            SELECT id, public_ip, public_port_from, public_port_to,
                   private_ip, private_port_from, private_port_to, protocol
            FROM public_port_forwards
            WHERE enabled = 1
            ORDER BY public_ip, protocol, public_port_from, public_port_to, private_ip
        ");
    }

    /** Port forwardy se souměrným a platným rozsahem portů (pro textový export). */
    public function symmetricPortForwards(): array
    {
        return DB::select("
            SELECT protocol, public_ip, public_port_from, public_port_to,
                   private_ip, private_port_from, private_port_to
            FROM public_port_forwards
            WHERE enabled = 1
              AND (public_port_to - public_port_from) = (private_port_to - private_port_from)
              AND public_port_from <= public_port_to
              AND private_port_from <= private_port_to
            ORDER BY public_ip, protocol, public_port_from, public_port_to, private_ip
        ");
    }

    public function nat1to1(): array
    {
        return DB::select("
            SELECT id, public_ip, private_ip, scope, comment
            FROM public_ip_nat_1to1
            WHERE enabled = 1 AND private_ip IS NOT NULL AND private_ip <> ''
            ORDER BY scope, public_ip
        ");
    }

    public function nat1to1Lines(?string $scope): array
    {
        return $scope !== null
            ? DB::select("
                SELECT public_ip, private_ip, comment
                FROM public_ip_nat_1to1
                WHERE enabled = 1 AND scope = ? AND private_ip IS NOT NULL AND private_ip <> ''
                ORDER BY public_ip
            ", [$scope])
            : DB::select("
                SELECT public_ip, private_ip, comment
                FROM public_ip_nat_1to1
                WHERE enabled = 1 AND private_ip IS NOT NULL AND private_ip <> ''
                ORDER BY scope, public_ip
            ");
    }

    public function smtpExceptions(): array
    {
        return DB::select("SELECT id, intip, user, datum FROM smtp_exceptions ORDER BY INET_ATON(intip)");
    }
}
