<?php

namespace App\Domains\Access\Listeners;

use App\Domains\Access\Events\IpoeReservationsChanged;
use App\Domains\Access\Kea\KeaControlClient;

/**
 * Pročistí Kea host-cache na všech uzlech, jinak Kea drží dřív zacachovaný
 * pool/negative verdikt a změna rezervace se neprojeví (reboot/renew to
 * nespraví). Best-effort. Viz [[project_lineid_tr101_gotcha]].
 */
class FlushKeaHostCache
{
    public function __construct(private KeaControlClient $kea)
    {
    }

    public function handle(IpoeReservationsChanged $event): void
    {
        $event->cacheFlushed = $this->kea->flushHostCache();
    }
}
