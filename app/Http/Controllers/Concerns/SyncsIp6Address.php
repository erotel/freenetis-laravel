<?php

namespace App\Http\Controllers\Concerns;

use App\Domains\Network\Support\Ip6Sync;

/** Auto-sync IPv6 k IPv4 — viz App\Domains\Network\Support\Ip6Sync. */
trait SyncsIp6Address
{
    protected function syncIp6Add(?int $ifaceId, string $ipv4): void
    {
        Ip6Sync::add($ifaceId, $ipv4);
    }

    protected function syncIp6Delete(string $ipv4): void
    {
        Ip6Sync::delete($ipv4);
    }
}
