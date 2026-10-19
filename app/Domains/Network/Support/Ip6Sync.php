<?php

namespace App\Domains\Network\Support;

use App\Models\Ip6Address;

/** Odvozená IPv6 /56 (Ip6FromIpv4) se zakládá / maže spolu s IPv4 rozhraní. */
final class Ip6Sync
{
    public static function add(?int $ifaceId, string $ipv4): void
    {
        if ($ifaceId && ($ip6 = Ip6FromIpv4::derive($ipv4))) {
            Ip6Address::create(['iface_id' => $ifaceId, 'ip_address' => $ip6, 'service' => 0]);
        }
    }

    public static function delete(string $ipv4): void
    {
        if ($ip6 = Ip6FromIpv4::derive($ipv4)) {
            Ip6Address::where('ip_address', $ip6)->delete();
        }
    }
}
