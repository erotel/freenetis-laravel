<?php

namespace Tests\Feature\Access;

use App\Domains\Access\Events\IpoeReservationsChanged;
use App\Domains\Access\Kea\KeaControlClient;
use App\Domains\Access\Listeners\FlushKeaHostCache;
use App\Models\Subnet;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\DatabaseTestCase;

/**
 * Event IpoeReservationsChanged → listener FlushKeaHostCache (Kea host-cache).
 * Registrace v AccessServiceProvider; výsledek flushe nese event (cacheFlushed).
 *
 * Každá změna IP v IPoE subnetu (dhcp+ipoe) jde přes Subnet::setExpired() →
 * odložený flush, jednou za request (KeaFlushScheduler, po odeslání odpovědi).
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

    private function subnet(int $dhcp, int $ipoe): Subnet
    {
        return Subnet::create([
            'name' => 'char ipoe', 'network_address' => '10.250.9.0', 'netmask' => '255.255.255.0',
            'dhcp' => $dhcp, 'ipoe' => $ipoe,
        ]);
    }

    public function test_set_expired_na_ipoe_subnetu_dispatchne_odlozeny_event(): void
    {
        Event::fake([IpoeReservationsChanged::class]);
        $s = $this->subnet(1, 1);
        $s->setExpired();

        Event::assertDispatched(IpoeReservationsChanged::class,
            fn ($e) => $e->deferred && $e->source === "subnet:{$s->id}");
    }

    public function test_set_expired_mimo_ipoe_nic(): void
    {
        Event::fake([IpoeReservationsChanged::class]);
        $this->subnet(1, 0)->setExpired();
        $this->subnet(0, 1)->setExpired();

        Event::assertNotDispatched(IpoeReservationsChanged::class);
    }

    public function test_vic_zmen_v_requestu_jeden_flush_az_na_konci(): void
    {
        $flushes = 0;
        $kea = $this->mock(KeaControlClient::class);
        $kea->shouldReceive('flushHostCache')->andReturnUsing(function () use (&$flushes) {
            $flushes++;
            return true;
        });
        $a = $this->subnet(1, 1);
        $a->setExpired();
        $a->setExpired();
        $this->subnet(1, 1)->setExpired();

        $this->assertSame(0, $flushes, 'během requestu se na Kea nečeká');
        $this->app->terminate();
        $this->assertSame(1, $flushes);

        // po flushi se dá naplánovat znovu
        $this->assertFalse(app(\App\Domains\Access\Kea\KeaFlushScheduler::class)->isScheduled());
    }

    public function test_smazani_ip_v_ipoe_subnetu_procisti_kea(): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true);
        $kea = $this->mock(KeaControlClient::class);
        $kea->shouldReceive('flushHostCache')->once()->andReturn(true);

        $ipId = (int) DB::table('ip_addresses')->insertGetId([
            'subnet_id' => $this->subnet(1, 1)->id, 'ip_address' => '10.250.9.20', 'gateway' => 0,
        ]);
        $admin = User::find((int) DB::table('users')->where('type', 1)->value('id'));

        $this->actingAs($admin)->delete(route('ip_addresses.destroy', $ipId))->assertRedirect();
    }

    public function test_prepnuti_subnetu_na_ipoe_procisti_kea(): void
    {
        Event::fake([IpoeReservationsChanged::class]);
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true);
        $s = $this->subnet(1, 0);
        $admin = User::find((int) DB::table('users')->where('type', 1)->value('id'));

        $this->actingAs($admin)->put(route('subnets.update', $s->id), [
            'name' => 'char ipoe', 'network_address' => '10.250.9.0', 'netmask' => '255.255.255.0',
            'dhcp' => 1, 'ipoe' => 1,
        ]);

        $this->assertTrue((bool) $s->fresh()->ipoe, 'subnet se přepnul');
        Event::assertDispatched(IpoeReservationsChanged::class, fn ($e) => $e->source === "subnet:{$s->id}");
    }
}
