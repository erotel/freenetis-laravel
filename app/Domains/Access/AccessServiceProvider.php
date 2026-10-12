<?php

namespace App\Domains\Access;

use App\Domains\Access\Events\DeviceRegisteredFromRequest;
use App\Domains\Access\Events\IpoeReservationsChanged;
use App\Domains\Access\Kea\KeaFlushScheduler;
use App\Domains\Access\Listeners\FlushKeaHostCache;
use App\Domains\Access\Listeners\ProvisionPppoeCredentials;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Registrace eventů domény Access. Listenery běží synchronně v pořadí registrace. */
class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KeaFlushScheduler::class);
    }

    public function boot(): void
    {
        Event::listen(IpoeReservationsChanged::class, FlushKeaHostCache::class);
        Event::listen(DeviceRegisteredFromRequest::class, ProvisionPppoeCredentials::class);
    }
}
