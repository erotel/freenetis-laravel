<?php

namespace Tests\Characterization\Console;

use App\Models\Message;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace members:redirect-former (denně 00:09): v den odchodu člena
 * překlopí na bývalého (90 → 15, ostatní → 16, zámek, bez rychlosti), zamkne
 * bývalé s dnes nastalým odchodem, přesměrování „bývalý člen“ (zpráva 19)
 * na IP bývalých po datu odchodu — přestavba od nuly.
 */
class RedirectFormerTest extends DatabaseTestCase
{
    private int $msg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->msg = (int) Message::where('type', 19)->value('id');
        if (!$this->msg) {
            $this->markTestSkipped('chybí zpráva typu 19');
        }
        Setting::set('former_member_auto_device_remove', 0);
    }

    private function member(int $type, string $leaving, int $locked = 0): int
    {
        return (int) DB::table('members')->insertGetId(['name' => 'Char Former', 'type' => $type, 'registration' => 1, 'locked' => $locked,
            'speed_class_id' => (int) DB::table('speed_classes')->value('id'),
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'), 'entrance_date' => '2020-01-01', 'leaving_date' => $leaving]);
    }

    private function ip(int $member, string $ip): int
    {
        $u = (int) DB::table('users')->insertGetId(['member_id' => $member, 'login' => 'charfo_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'F', 'comment' => '']);
        $d = (int) DB::table('devices')->insertGetId(['name' => 'char-fo', 'type' => 1, 'user_id' => $u]);
        $i = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'e', 'device_id' => $d]);
        return (int) DB::table('ip_addresses')->insertGetId(['ip_address' => $ip, 'iface_id' => $i]);
    }

    private function row(int $id): array
    {
        $r = DB::table('members')->where('id', $id)->first();
        return [(int) $r->type, (int) $r->locked, $r->speed_class_id];
    }

    public function test_den_odchodu_a_presmerovani(): void
    {
        $today = now()->toDateString();
        $regular  = $this->member(90, $today);
        $customer = $this->member(2, now()->subDay()->toDateString());
        $future   = $this->member(2, now()->addDays(5)->toDateString());
        $noticed  = $this->member(16, $today);                       // ukončen dřív s budoucím datem, dnes nastalo
        $old      = $this->member(15, '2020-01-01', 1);
        $noDate   = $this->member(16, '9999-12-31', 1);
        $ips = [$this->ip($regular, '10.246.0.1'), $this->ip($customer, '10.246.0.2'), $this->ip($future, '10.246.0.3'),
                $this->ip($noticed, '10.246.0.4'), $this->ip($old, '10.246.0.5'), $this->ip($noDate, '10.246.0.6')];
        $stale = $ips[2];
        $direct = (int) DB::table('ip_addresses')->insertGetId(['ip_address' => '10.246.0.7', 'member_id' => $customer]);
        DB::table('messages_ip_addresses')->insert(['message_id' => $this->msg, 'ip_address_id' => $stale, 'datetime' => now()]);

        $this->artisan('members:redirect-former', ['--force' => true])->assertExitCode(0);

        $this->assertSame([15, 1, null], $this->row($regular));
        $this->assertSame([16, 1, null], $this->row($customer));
        $this->assertSame(2, $this->row($future)[0], 'budoucí odchod beze změny');
        $this->assertSame([16, 1, null], $this->row($noticed), 'dnes nastalý odchod → zámek + bez rychlosti');
        $audit = DB::table('audit_logs')->where('action', 'auto_former')->orderByDesc('id')->first();
        $ids = json_decode($audit->new_values, true)['member_ids'];
        $this->assertContains($regular, $ids);
        $this->assertContains($customer, $ids);
        $this->assertNotContains($noticed, $ids, 'už bývalý se nepřeklápí');

        $redirected = DB::table('messages_ip_addresses')->where('message_id', $this->msg)->whereIn('ip_address_id', $ips)->pluck('ip_address_id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $this->assertSame([$ips[0], $ips[1], $ips[3], $ips[4]], $redirected, 'bývalí po odchodu; ne budoucí, ne bez data; staré přesměrování smazáno');
        $row = DB::table('messages_ip_addresses')->where('message_id', $this->msg)->where('ip_address_id', $ips[0])->first();
        $this->assertTrue(DB::table('messages_ip_addresses')->where('message_id', $this->msg)->where('ip_address_id', $direct)->exists(), 'i přímo přiřazená IP (IpOwner)');
        $this->assertSame([1, 'Auto: bývalý člen'], [(int) $row->user_id, $row->comment]);
    }
}
