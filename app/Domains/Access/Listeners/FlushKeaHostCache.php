<?php

namespace App\Domains\Access\Listeners;

use App\Domains\Access\Events\IpoeReservationsChanged;
use App\Domains\Access\Kea\KeaControlClient;
use App\Domains\Access\Kea\KeaFlushScheduler;

/**
 * Pročistí Kea host-cache na všech uzlech, jinak Kea drží dřív zacachovaný
 * pool/negative verdikt a změna rezervace se neprojeví (reboot/renew to
 * nespraví). Best-effort. Viz [[project_lineid_tr101_gotcha]].
 */
class FlushKeaHostCache
{
    public function __construct(private KeaControlClient $kea, private KeaFlushScheduler $scheduler)
    {
    }

    public function handle(IpoeReservationsChanged $event): void
    {
        if ($event->deferred) {
            $this->scheduler->schedule();
            return;
        }
        $event->cacheFlushed = $this->kea->flushHostCache();
    }
}
