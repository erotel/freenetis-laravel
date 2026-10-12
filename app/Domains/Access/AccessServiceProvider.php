<?php

namespace App\Domains\Access;

use App\Domains\Access\Events\IpoeReservationsChanged;
use App\Domains\Access\Listeners\FlushKeaHostCache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Registrace eventů domény Access. Listenery běží synchronně v pořadí registrace. */
class AccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(IpoeReservationsChanged::class, FlushKeaHostCache::class);
    }
}
