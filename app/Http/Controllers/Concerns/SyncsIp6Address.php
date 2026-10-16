<?php

namespace App\Http\Controllers\Concerns;

use App\Domains\Network\Support\Ip6FromIpv4;
use App\Models\Ip6Address;

/**
 * Auto-sync IPv6 addresses when IPv4 addresses are created, updated, or deleted.
 *
 * Výpočet adresy: App\Domains\Network\Support\Ip6FromIpv4.
 */
trait SyncsIp6Address
{
    private function calcIp6Address(string $ipv4): ?string
    {
        return Ip6FromIpv4::derive($ipv4);
    }

    protected function syncIp6Add(?int $ifaceId, string $ipv4): void
    {
        if (!$ifaceId) {
            return;
        }
        $ip6 = $this->calcIp6Address($ipv4);
        if ($ip6) {
            Ip6Address::create(['iface_id' => $ifaceId, 'ip_address' => $ip6, 'service' => 0]);
        }
    }

    protected function syncIp6Delete(string $ipv4): void
    {
        $ip6 = $this->calcIp6Address($ipv4);
        if ($ip6) {
            Ip6Address::where('ip_address', $ip6)->delete();
        }
    }
}
