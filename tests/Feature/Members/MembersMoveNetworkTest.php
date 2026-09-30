<?php

namespace Tests\Feature\Members;

use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * members:move-network — převod síťové části člena (zařízení, přímé IP,
 * vlastnictví subnetů, allowed_subnets) na jiného člena; účetnictví zůstává.
 */
class MembersMoveNetworkTest extends DatabaseTestCase
{
    private int $from;
    private int $to = 2748;
    private int $toUser;
    private array $backupsBefore;

    protected function setUp(): void
    {
        parent::setUp();

        // Zdroj = člen s aspoň jedním zařízením s IP v subnetu (mimo cíl).
        $this->from = (int) DB::table('ip_addresses as ip')
            ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->whereNotNull('ip.subnet_id')
            ->where('u.member_id', '<>', $this->to)
            ->value('u.member_id');
        $this->toUser = (int) DB::table('users')->where('member_id', $this->to)->orderByRaw('type <> 1')->orderBy('id')->value('id');
        if (!$this->from || !$this->toUser) {
            $this->markTestSkipped('chybí zdrojový člen se zařízením nebo cílový člen 2748');
        }
        DB::table('allowed_subnets_counts')->updateOrInsert(['member_id' => $this->to], ['count' => 0]);
        $this->backupsBefore = glob(storage_path('app/move_network_*.json')) ?: [];
    }

    protected function tearDown(): void
    {
        // Záloha z testu je na disku mimo transakci — uklidit.
        foreach (array_diff(glob(storage_path('app/move_network_*.json')) ?: [], $this->backupsBefore) as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function deviceCount(int $memberId): int
    {
        return DB::table('devices as d')->join('users as u', 'u.id', '=', 'd.user_id')->where('u.member_id', $memberId)->count();
    }

    public function test_bez_execute_nic_nezmeni(): void
    {
        $before = $this->deviceCount($this->from);
        $this->artisan('members:move-network', ['from' => $this->from, 'to' => $this->to])
            ->expectsOutputToContain('Náhled')
            ->assertSuccessful();
        $this->assertSame($before, $this->deviceCount($this->from));
    }

    public function test_execute_prevede_zarizeni_ip_vlastnictvi_a_povolene_podsite(): void
    {
        $devices = $this->deviceCount($this->from);
        $toBefore = $this->deviceCount($this->to);
        $subnet = (int) DB::table('ip_addresses as ip')
            ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('u.member_id', $this->from)->whereNotNull('ip.subnet_id')->value('ip.subnet_id');
        $ownedSubnet = (int) DB::table('subnets')->insertGetId(['name' => 'NET test-move', 'network_address' => '10.133.254.0', 'netmask' => '255.255.255.0']);
        DB::table('subnets_owners')->insert(['subnet_id' => $ownedSubnet, 'member_id' => $this->from]);
        DB::table('ip_addresses')->insert(['subnet_id' => $ownedSubnet, 'member_id' => $this->from, 'ip_address' => '10.133.254.10', 'gateway' => 0]);
        $accountsBefore = DB::table('accounts')->where('member_id', $this->from)->count();

        $this->artisan('members:move-network', ['from' => $this->from, 'to' => $this->to, '--execute' => true])
            ->assertSuccessful();

        $this->assertSame(0, $this->deviceCount($this->from));
        $this->assertSame($toBefore + $devices, $this->deviceCount($this->to));
        $this->assertSame($this->to, (int) DB::table('ip_addresses')->where('ip_address', '10.133.254.10')->value('member_id'));
        $this->assertSame($this->to, (int) DB::table('subnets_owners')->where('subnet_id', $ownedSubnet)->value('member_id'));
        $this->assertTrue(DB::table('allowed_subnets')->where('member_id', $this->to)->where('subnet_id', $subnet)->where('enabled', 1)->exists());
        $this->assertFalse(DB::table('allowed_subnets')->where('member_id', $this->from)->where('subnet_id', $subnet)->exists());
        $this->assertSame($accountsBefore, DB::table('accounts')->where('member_id', $this->from)->count(), 'účetnictví zůstává');
    }

    public function test_omezeny_limit_cile_odmitne(): void
    {
        DB::table('allowed_subnets_counts')->updateOrInsert(['member_id' => $this->to], ['count' => 1]);
        $before = $this->deviceCount($this->from);

        $this->artisan('members:move-network', ['from' => $this->from, 'to' => $this->to, '--execute' => true])
            ->assertFailed();
        $this->assertSame($before, $this->deviceCount($this->from));
    }
}
