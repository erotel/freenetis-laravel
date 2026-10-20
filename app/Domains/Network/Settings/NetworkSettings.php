<?php

namespace App\Domains\Network\Settings;

use App\Domains\Access\Isolation\LanIsolationService;
use App\Models\Setting;

/**
 * Nastavení → Síť: přesměrování, sítě, M2M (allowlist, vynucení tokenu),
 * PPPoE, DNS, IPv6, DHCP (lease, relay mapa, výchozí počet podsítí) a izolace
 * klientů od LAN (síť, veřejné služby, výjimky zařízení / infra členové).
 */
class NetworkSettings
{
    public const KEYS = [
        'redirection_enabled', 'networks_enabled', 'address_ranges', 'dns_servers',
        'ipv6_prefix', 'ipv6_mask', 'connection_request_notify_email',
        'dhcp_lease_time', 'allowed_subnets_default_count', 'dhcp_relay_map',
        'web_interface_require_token', 'pppoe_enabled',
        'lan_isolation_devices', 'lan_isolation_network', 'lan_isolation_public', 'lan_isolation_infra_members',
    ];

    private const LAN_ID_KEYS = ['lan_isolation_devices', 'lan_isolation_infra_members'];

    public function __construct(private LanIsolationService $lan)
    {
    }

    public function values(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = Setting::get($key, '');
        }
        return $out;
    }

    /**
     * Zvaliduje a znormalizuje izolaci LAN.
     * @param array<string, mixed> $input lan_isolation_* ze vstupu
     * @return array{0: array<string,string>, 1: array<string,string>} [hodnoty k uložení, chyby podle pole]
     */
    public function normalizeLanIsolation(array $input): array
    {
        $errors = [];
        $network = null;
        try {
            $network = $this->lan->normalizeDestination(
                trim((string) ($input['lan_isolation_network'] ?? '')) ?: LanIsolationService::DEFAULT_NETWORK,
                false
            );
        } catch (\InvalidArgumentException $e) {
            $errors['lan_isolation_network'] = $e->getMessage();
        }
        // veřejné služby: libovolné IPv4/CIDR (mimo síť izolace jsou jen neškodně navíc)
        [$public, $pubErrors] = $this->lan->parseList((string) ($input['lan_isolation_public'] ?? ''), false);
        if ($pubErrors) {
            $errors['lan_isolation_public'] = implode(' ', $pubErrors);
        }
        foreach (self::LAN_ID_KEYS as $key) {
            if (preg_match('/[^\d\s,;]/', (string) ($input[$key] ?? ''))) {
                $errors[$key] = 'Zadej jen číselná ID oddělená čárkou nebo novým řádkem.';
            }
        }
        if ($errors) {
            return [[], $errors];
        }

        $idLines = fn (string $key) => implode("\n", array_values(array_unique(array_filter(
            array_map('intval', preg_split('/[\s,;]+/', (string) ($input[$key] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
        ))));
        return [[
            'lan_isolation_devices'       => $idLines('lan_isolation_devices'),
            'lan_isolation_network'       => $network,
            'lan_isolation_public'        => implode("\n", $public),
            'lan_isolation_infra_members' => $idLines('lan_isolation_infra_members'),
        ], []];
    }

    /** Uloží izolaci; při změně expiruje DHCP podsítě (routery si stáhnou novou konfiguraci). */
    public function saveLanIsolation(array $values): void
    {
        $changed = false;
        foreach ($values as $key => $value) {
            if ((string) Setting::get($key, '') !== $value) {
                Setting::set($key, $value);
                $changed = true;
            }
        }
        if ($changed) {
            $this->lan->expireAllDhcpSubnets();
        }
    }

    /**
     * @param array<string, bool>  $flags redirection_enabled, networks_enabled, web_interface_require_token, pppoe_enabled
     * @param array<string, mixed> $texts address_ranges, dns_servers, ipv6_prefix, ipv6_mask, connection_request_notify_email,
     *                                    dhcp_lease_time, allowed_subnets_default_count, dhcp_relay_map
     */
    public function save(array $flags, array $texts): void
    {
        foreach (['redirection_enabled', 'networks_enabled', 'web_interface_require_token', 'pppoe_enabled'] as $key) {
            Setting::set($key, $flags[$key] ? 1 : 0);
        }
        foreach (['address_ranges', 'dns_servers', 'ipv6_prefix', 'ipv6_mask', 'connection_request_notify_email', 'dhcp_lease_time'] as $key) {
            Setting::set($key, $texts[$key]);
        }
        Setting::set('allowed_subnets_default_count', (int) $texts['allowed_subnets_default_count']);
        Setting::set('dhcp_relay_map', self::normalizeRelayMap((string) $texts['dhcp_relay_map']));
    }

    /** „ID DHCP zařízení = relay rozhraní": řádky s „=", bez prázdných a CR. */
    private static function normalizeRelayMap(string $raw): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        return implode("\n", array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== '' && str_contains($l, '='))));
    }
}
