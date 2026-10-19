<?php

namespace App\Domains\Network\Support;

use App\Models\Setting;

/**
 * Odvozená IPv6 zákaznická síť z IPv4 (zrcadlí Kohana
 * Ip6_addresses_Controller::calc_ip6_address):
 *   10.W.Y.Z → {ipv6_prefix}:hex(Y):hex(Z)00::/{ipv6_mask}
 * Jen pro 10.x.x.x; jiné adresy IPv6 nedostanou.
 */
final class Ip6FromIpv4
{
    public static function derive(string $ipv4): ?string
    {
        return self::deriveWith($ipv4, Setting::get('ipv6_prefix', '2a07:9c0'), Setting::get('ipv6_mask', '56'));
    }

    /** Odvození s explicitním prefixem a maskou. */
    public static function deriveWith(string $ipv4, $prefix, $mask): ?string
    {
        $parts = explode('.', $ipv4);
        if (count($parts) !== 4 || $parts[0] !== '10') {
            return null;
        }
        $y = dechex((int) $parts[2]);
        $z = dechex((int) $parts[3]);
        return "{$prefix}:{$y}:{$z}00::/{$mask}";
    }
}
