<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Ip6Address;
use App\Models\Setting;

/**
 * Auto-sync IPv6 addresses when IPv4 addresses are created, updated, or deleted.
 *
 * Algorithm (mirrors Kohana Ip6_addresses_Controller::calc_ip6_address):
 *   IPv4 10.W.X.Y.Z  →  {ipv6_prefix}:hex(Y):hex(Z)00::/{ipv6_mask}
 *   Only 10.x.x.x addresses produce an IPv6 record; all others are silently skipped.
 */
trait SyncsIp6Address
{
    private function calcIp6Address(string $ipv4): ?string
    {
        $parts = explode('.', $ipv4);
        if (count($parts) !== 4 || $parts[0] !== '10') {
            return null;
        }
        $prefix = Setting::get('ipv6_prefix', '2a07:9c0');
        $mask   = Setting::get('ipv6_mask',   '56');
        $y = dechex((int) $parts[2]);
        $z = dechex((int) $parts[3]);
        return "{$prefix}:{$y}:{$z}00::/{$mask}";
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
