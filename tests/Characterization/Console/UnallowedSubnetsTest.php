<?php

namespace Tests\Characterization\Console;

use App\Models\Message;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace subnets:update-allowed: přesměrování „nepovolené místo
 * připojení“ pro IP členů mimo jejich povolené (enabled) podsítě — jen členové
 * s nějakým přípojným místem, ne sdružení; přestavba od nuly; vypnuto /
 * interval; vlastník IP podle IpOwner (přímá i přes zařízení).
 */
class UnallowedSubnetsTest extends DatabaseTestCase
{
    private int $msg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->msg = (int) Message::where('type', Message::UNALLOWED_CONNECTING_PLACE)->value('id');
        if (!$this->msg) {
            $this->markTestSkipped('chybí zpráva typu 7');
        }
    }

    private function member(): int
    {
        return (int) DB::table('members')->insertGetId(['name' => 'Char Unallowed', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'), 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
    }

    private function iface(int $member): int
    {
        $u = (int) DB::table('users')->insertGetId(['member_id' => $member, 'login' => 'charun_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'U', 'comment' => '']);
        $d = (int) DB::table('devices')->insertGetId(['name' => 'char-un', 'type' => 1, 'user_id' => $u]);
        return (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'e', 'device_id' => $d]);
    }

    private function subnet(string $net): int
    {
        return (int) DB::table('subnets')->insertGetId(['name' => "char {$net}", 'network_address' => $net, 'netmask' => '255.255.255.0']);
    }

    private function redirected(): array
    {
        return DB::table('messages_ip_addresses')->where('message_id', $this->msg)->orderBy('ip_address_id')->pluck('ip_address_id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_presmerovani_mimo_povolene(): void
    {
        $m = $this->member();
        $if = $this->iface($m);
        [$a, $b] = [$this->subnet('10.245.1.0'), $this->subnet('10.245.2.0')];
        DB::table('allowed_subnets')->insert(['member_id' => $m, 'subnet_id' => $a, 'enabled' => 1]);
        $inA  = (int) DB::table('ip_addresses')->insertGetId(['ip_address' => '10.245.1.5', 'subnet_id' => $a, 'iface_id' => $if]);
        $inB  = (int) DB::table('ip_addresses')->insertGetId(['ip_address' => '10.245.2.5', 'subnet_id' => $b, 'iface_id' => $if]);
        $direct = (int) DB::table('ip_addresses')->insertGetId(['ip_address' => '10.245.2.6', 'subnet_id' => $b, 'member_id' => $m]);
        $noPlaces = $this->iface($this->member());
        $free = (int) DB::table('ip_addresses')->insertGetId(['ip_address' => '10.245.2.7', 'subnet_id' => $b, 'iface_id' => $noPlaces]);
        $stale = (int) DB::table('ip_addresses')->insertGetId(['ip_address' => '10.245.1.9', 'subnet_id' => $a, 'iface_id' => $this->iface($m)]);
        DB::table('messages_ip_addresses')->insert(['message_id' => $this->msg, 'ip_address_id' => $stale, 'datetime' => now()]);

        Setting::set('allowed_subnets_enabled', '0');
        $this->artisan('subnets:update-allowed')->assertExitCode(0);
        $this->assertSame([$stale], array_values(array_intersect($this->redirected(), [$inA, $inB, $direct, $free, $stale])), 'vypnuto → nic');

        $this->artisan('subnets:update-allowed', ['--force' => true])->assertExitCode(0);
        $mine = array_values(array_intersect($this->redirected(), [$inA, $inB, $direct, $free, $stale]));
        $this->assertSame([$inB, $direct], $mine, 'jen IP mimo povolené, přímá i přes zařízení; staré smazáno');
        $row = DB::table('messages_ip_addresses')->where('message_id', $this->msg)->where('ip_address_id', $inB)->first();
        $this->assertSame([1, 'Auto: nepovolená podsíť'], [(int) $row->user_id, $row->comment]);
        $this->assertNotNull(Setting::get('allowed_subnets_update_state'));

        // interval
        Setting::set('allowed_subnets_enabled', '1');
        Setting::set('allowed_subnets_update_interval', '3600');
        Setting::set('allowed_subnets_update_state', now()->toDateTimeString());
        DB::table('allowed_subnets')->insert(['member_id' => $m, 'subnet_id' => $b, 'enabled' => 1]);
        $this->artisan('subnets:update-allowed')->assertExitCode(0);
        $this->assertContains($inB, $this->redirected(), 'interval neuplynul → beze změny');
        Setting::set('allowed_subnets_update_state', '2000-01-01 00:00:00');
        $this->artisan('subnets:update-allowed')->assertExitCode(0);
        $this->assertNotContains($inB, $this->redirected());
    }
}
