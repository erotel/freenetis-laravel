<?php

namespace App\Domains\Network;

use App\Domains\Network\Events\DeviceRemoved;
use App\Domains\Network\Listeners\ReleaseDeviceSubnets;
use App\Domains\Network\Listeners\WriteDeviceRemovalAudit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Registrace eventů domény Network. Listenery běží synchronně v pořadí registrace. */
class NetworkServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(DeviceRemoved::class, WriteDeviceRemovalAudit::class);
        Event::listen(DeviceRemoved::class, ReleaseDeviceSubnets::class);
    }
}
