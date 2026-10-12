<?php

namespace Tests\Feature\Access;

use App\Domains\Access\Events\IpoeReservationsChanged;
use App\Domains\Access\Kea\KeaControlClient;
use App\Domains\Access\Listeners\FlushKeaHostCache;
use Illuminate\Support\Facades\Event;
use Tests\DatabaseTestCase;

/**
 * Event IpoeReservationsChanged → listener FlushKeaHostCache (Kea host-cache).
 * Registrace v AccessServiceProvider; výsledek flushe nese event (cacheFlushed).
 */
class IpoeReservationsChangedTest extends DatabaseTestCase
{
    public function test_listener_je_registrovany(): void
    {
        Event::fake();
        Event::assertListening(IpoeReservationsChanged::class, FlushKeaHostCache::class);
    }

    public function test_event_nese_vysledek_flushe(): void
    {
        $kea = $this->mock(KeaControlClient::class);
        $kea->shouldReceive('flushHostCache')->once()->andReturn(true);

        $event = new IpoeReservationsChanged('test');
        event($event);
        $this->assertSame(true, $event->cacheFlushed);
    }

    public function test_selhany_flush_se_propise(): void
    {
        $kea = $this->mock(KeaControlClient::class);
        $kea->shouldReceive('flushHostCache')->once()->andReturn(false);

        $event = new IpoeReservationsChanged('test');
        event($event);
        $this->assertSame(false, $event->cacheFlushed);
    }
}
