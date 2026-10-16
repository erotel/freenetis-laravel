<?php

namespace Tests\Characterization\Network;

use App\Models\ConnectionRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace čtecí strany DeviceController před přesunem do domény Network:
 * seznam (hledání, řazení), zařízení uživatele a detail (vlastní vs. cizí),
 * formuláře (výběr člena, volné IP v podsítích, rozhraní ze šablony, předvyplnění
 * ze žádosti) a přehled DHCP serverů. CSV inventář: Feature/Devices/DeviceInventoryCsvTest.
 */
class DeviceReadTest extends DatabaseTestCase
{
    private int $adminId;
    private int $memberId;
    private int $userId;
    private int $townId;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->townId  = (int) DB::table('towns')->value('id');
        if (!$this->adminId || !$this->townId) {
            $this->markTestSkipped('chybí admin / obec');
        }
        $this->acl();
        $this->tag = 'zq' . substr(md5(uniqid('', true)), 0, 8);

        $streetId = (int) DB::table('streets')->insertGetId(['street' => 'Ulice' . $this->tag]);
        $ap = (int) DB::table('address_points')->insertGetId(['town_id' => $this->townId, 'street_id' => $streetId, 'street_number' => '3', 'country_id' => 1]);
        $this->memberId = (int) DB::table('members')->insertGetId([
            'name' => 'Char Read Dev', 'type' => 2, 'leaving_date' => '9999-12-31', 'registration' => 1, 'locked' => 0, 'address_point_id' => $ap,
        ]);
        $this->userId = $this->user($this->memberId, 1, 'Novak' . $this->tag, 'Petr');
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function user(int $memberId, int $type, string $surname = 'Dev', string $name = 'Char'): int
    {
        return (int) DB::table('users')->insertGetId([
            'member_id' => $memberId, 'login' => 'charrd_' . uniqid(), 'password' => Hash::make('x'), 'type' => $type,
            'application_password' => 'xxxxxxxx', 'settings' => '', 'name' => $name, 'surname' => $surname, 'comment' => '',
        ]);
    }

    /** @return array{device:int, iface:int} */
    private function device(string $name, array $d = [], ?string $mac = null, ?string $ip = null, ?int $subnet = null): array
    {
        $dev = (int) DB::table('devices')->insertGetId($d + ['name' => $name, 'type' => 8, 'user_id' => $this->userId]);
        $iface = (int) DB::table('ifaces')->insertGetId(['type' => 2, 'device_id' => $dev, 'name' => 'ether1', 'mac' => $mac]);
        if ($ip) {
            DB::table('ip_addresses')->insert(['iface_id' => $iface, 'subnet_id' => $subnet, 'ip_address' => $ip]);
        }
        return ['device' => $dev, 'iface' => $iface];
    }

    private function as(?int $userId = null)
    {
        return $this->actingAs(User::find($userId ?? $this->adminId));
    }

    private function listed(array $q): array
    {
        return collect($this->as()->get(route('devices.index', $q))->assertOk()->viewData('devices')->items())
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    // ── seznam ───────────────────────────────────────────────────────────────

    public function test_seznam_hledani_a_razeni(): void
    {
        $subnet = (int) DB::table('subnets')->insertGetId(['name' => 'char rd', 'network_address' => '10.250.13.0', 'netmask' => '255.255.255.0']);
        $mac = '02:EE:' . strtoupper(substr($this->tag, 2, 2)) . ':' . strtoupper(substr($this->tag, 4, 2)) . ':01:02';
        $a = $this->device("a-{$this->tag}", [], $mac, '10.250.13.77', $subnet)['device'];
        $b = $this->device("b-{$this->tag}", ['address_point_id' => DB::table('members')->where('id', $this->memberId)->value('address_point_id')])['device'];

        $this->assertEqualsCanonicalizing([$a, $b], $this->listed(['search' => $this->tag]));
        $this->assertSame([$a], $this->listed(['search' => $mac]), 'MAC');
        $this->assertSame([$a], $this->listed(['search' => '10.250.13.77']), 'IPv4');
        $this->assertSame([$b], $this->listed(['search' => 'Ulice' . $this->tag]), 'adresa');
        $this->assertSame([$b], $this->listed(['search' => "b-{$this->tag} Ulice{$this->tag}"]), 'víc slov');
        $this->assertSame([$b, $a], $this->listed(['search' => $this->tag, 'sort' => 'name', 'dir' => 'desc']));

        $this->as()->get(route('devices.index', ['sort' => 'password', 'record_per_page' => 3]))
            ->assertViewHas('sort', 'id')->assertViewHas('perPage', 50)->assertViewHas('canNew', true);

        $this->acl(['view_all/Devices_Controller/devices']);
        $this->as()->get(route('devices.index'))->assertForbidden();
    }

    // ── zařízení uživatele / detail ──────────────────────────────────────────

    public function test_zarizeni_uzivatele_a_detail_vlastni_vs_cizi(): void
    {
        $a = $this->device("a-{$this->tag}")['device'];
        $b = $this->device("b-{$this->tag}")['device'];
        $tech = $this->user($this->memberId, 2, 'Technik');
        DB::table('device_engineers')->insert(['device_id' => $a, 'user_id' => $tech]);

        $this->as()->get(route('devices.by_user', $this->userId))->assertOk()
            ->assertViewHas('devices', fn ($d) => $d->pluck('id')->map(fn ($i) => (int) $i)->all() === [$a, $b])
            ->assertViewHas('memberId', $this->memberId)->assertViewHas('canRequestConnection', false);
        $this->as()->get(route('devices.by_user', [$this->userId, 'sort' => 'name', 'dir' => 'desc']))
            ->assertViewHas('devices', fn ($d) => (int) $d->first()->id === $b);

        $this->as()->get(route('devices.show', $a))->assertOk()
            ->assertViewHas('engineerUsers', fn ($u) => !$u->contains('id', $tech) && $u->contains('id', $this->userId))
            ->assertViewHas('canViewAll', true);

        // vlastník bez práva vidí svoje, cizí ne
        $otherMember = (int) DB::table('members')->insertGetId(['name' => 'Cizi', 'type' => 2, 'leaving_date' => '9999-12-31', 'registration' => 1, 'locked' => 0,
            'address_point_id' => DB::table('members')->where('id', $this->memberId)->value('address_point_id')]);
        $other = $this->user($otherMember, 1);
        $this->acl(['view_all/Devices_Controller/devices']);
        $this->as($this->userId)->get(route('devices.show', $a))->assertOk()->assertViewHas('canViewAll', false);
        $this->as($this->userId)->get(route('devices.by_user', $this->userId))->assertOk();
        $this->as($other)->get(route('devices.show', $a))->assertForbidden();
        $this->as($other)->get(route('devices.by_user', $this->userId))->assertForbidden();

        $this->acl();
        $this->as()->get(route('devices.show', 999999999))->assertNotFound();
        $this->as()->get(route('devices.by_user', 999999999))->assertNotFound();
    }

    public function test_detail_pppoe_jen_se_zapnutym_modulem_a_pravem_na_heslo(): void
    {
        $x = $this->device("a-{$this->tag}");
        DB::table('pppoe_secrets')->insert(['iface_id' => $x['iface'], 'username' => 'u_' . $this->tag, 'secret' => 'tajne']);

        Setting::set('pppoe_enabled', '1');
        $this->as()->get(route('devices.show', $x['device']))->assertViewHas('pppoeSecrets', fn ($s) => $s->has($x['iface']));
        $this->acl(['view_all/Devices_Controller/password']);
        $this->as()->get(route('devices.show', $x['device']))->assertViewHas('pppoeSecrets', fn ($s) => $s->isEmpty());
        $this->acl();
        Setting::set('pppoe_enabled', '0');
        $this->as()->get(route('devices.show', $x['device']))->assertViewHas('pppoeEnabled', false)->assertViewHas('pppoeSecrets', fn ($s) => $s->isEmpty());
    }

    // ── formuláře ────────────────────────────────────────────────────────────

    public function test_formular_sablona_vyber_clena_volne_ip_a_rozhrani(): void
    {
        $this->user($this->memberId, 1, 'Aaa');   // druhý hlavní uživatel téhož člena (legacy) — člen jen jednou
        $subnet = (int) DB::table('subnets')->insertGetId(['name' => 'char rd', 'network_address' => '10.250.14.0', 'netmask' => '255.255.255.248']);
        DB::table('ip_addresses')->insert(['subnet_id' => $subnet, 'ip_address' => '10.250.14.2']);
        $tpl = (int) DB::table('device_templates')->insertGetId(['enum_type_id' => 8, 'name' => 'char rd',
            'values' => json_encode(['2' => ['count' => 1, 'items' => [], 'type' => 2, 'has_ip' => true, 'has_mac' => true]])]);

        $res = $this->as()->get(route('devices.add', ['userId' => $this->userId, 'template_id' => $tpl, 'type_id' => 8]))->assertOk();
        $res->assertViewHas('preselectedMemberId', $this->memberId)->assertViewHas('selectedTypeId', 8)
            ->assertViewHas('selectedTemplate', fn ($t) => (int) $t->id === $tpl)
            ->assertViewHas('ifaceDefinitions', fn ($d) => count($d) === 1 && $d[0]['has_ip'] === true)
            ->assertViewHas('templates', fn ($t) => $t->contains(fn ($x) => $x['id'] === $tpl && $x['name'] === 'char rd (' . DB::table('enum_types')->where('id', 8)->value('value') . ')'))
            ->assertViewHas('subnetData', fn ($s) => collect($s)->firstWhere('id', $subnet)['freeIps'] === ['10.250.14.1', '10.250.14.3', '10.250.14.4', '10.250.14.5', '10.250.14.6']);

        $row = collect($res->viewData('members'))->where('id', $this->memberId);
        $this->assertCount(1, $row, 'člen s více hlavními uživateli jen jednou');
        $this->assertSame(['Aaa Char', 'Ulice' . $this->tag . ' 3, ' . trim(DB::table('towns')->where('id', $this->townId)->value('town') . ' ' . DB::table('towns')->where('id', $this->townId)->value('zip_code'))],
            [$row->first()->display_name, $row->first()->address_label]);

        $this->as()->get(route('devices.add', ['member_id' => 123]))->assertViewHas('preselectedMemberId', 123)->assertViewHas('selectedTemplate', null);
        $this->acl(['new_all/Devices_Controller/devices']);
        $this->as()->get(route('devices.add'))->assertForbidden();
    }

    public function test_formular_ze_zadosti_predvyplni(): void
    {
        $subnet = (int) DB::table('subnets')->insertGetId(['name' => 'char rd', 'network_address' => '10.250.15.0', 'netmask' => '255.255.255.0']);
        $cr = (int) DB::table('connection_requests')->insertGetId(['member_id' => $this->memberId, 'state' => ConnectionRequest::STATE_UNDECIDED, 'created_at' => now(),
            'mac_address' => '02:AA:BB:CC:DD:EE', 'ip_address' => '10.250.15.9', 'subnet_id' => $subnet, 'device_type_id' => 8]);

        $this->as()->get(route('devices.create_from_cr', $cr))->assertOk()
            ->assertViewHas('preselectedMemberId', $this->memberId)->assertViewHas('preselectedMac', '02:AA:BB:CC:DD:EE')
            ->assertViewHas('preselectedIp', '10.250.15.9')->assertViewHas('preselectedSubnetId', $subnet)
            ->assertViewHas('connectionRequestId', $cr)->assertViewHas('selectedTypeId', 8)
            ->assertViewHas('user', fn ($u) => (int) $u->id === $this->userId);
        $this->as()->get(route('devices.create_from_cr', 999999999))->assertNotFound();
    }

    public function test_formular_zalozeni_a_upravy(): void
    {
        $this->as()->get(route('devices.create', ['user_id' => $this->userId]))->assertOk()
            ->assertViewHas('preselectedMemberId', $this->memberId)->assertViewHas('canEditPassword', true)
            ->assertViewHas('deviceTypes', fn ($t) => $t->has(8));

        $dev = $this->device("a-{$this->tag}", ['address_point_id' => DB::table('members')->where('id', $this->memberId)->value('address_point_id')])['device'];
        $this->as()->get(route('devices.edit', $dev))->assertOk()
            ->assertViewHas('currentMemberId', $this->memberId)->assertViewHas('currentTownId', $this->townId)->assertViewHas('currentStreetNumber', '3');
        $this->as()->get(route('devices.edit', 999999999))->assertNotFound();

        $this->acl(['view_all/Devices_Controller/password']);
        $this->as()->get(route('devices.create'))->assertViewHas('canEditPassword', false)->assertViewHas('preselectedMemberId', null);
    }

    // ── DHCP servery ─────────────────────────────────────────────────────────

    public function test_dhcp_servery(): void
    {
        $on  = (int) DB::table('subnets')->insertGetId(['name' => 'char on', 'network_address' => '10.250.16.0', 'netmask' => '255.255.255.0', 'dhcp' => 1, 'dhcp_expired' => 1]);
        $off = (int) DB::table('subnets')->insertGetId(['name' => 'char off', 'network_address' => '10.250.17.0', 'netmask' => '255.255.255.0', 'dhcp' => 0, 'dhcp_expired' => 1]);
        $gw  = $this->device("gw-{$this->tag}", ['access_time' => now()->subMinutes(30)]);
        DB::table('ip_addresses')->insert(['iface_id' => $gw['iface'], 'subnet_id' => $on, 'ip_address' => '10.250.16.1', 'gateway' => 1, 'dhcp' => 1]);
        $offGw = $this->device("off-{$this->tag}");
        DB::table('ip_addresses')->insert(['iface_id' => $offGw['iface'], 'subnet_id' => $off, 'ip_address' => '10.250.17.1', 'gateway' => 1, 'dhcp' => 1]);

        $rows = collect($this->as()->get(route('devices.dhcp-servers'))->assertOk()->viewData('devices'));
        $row = $rows->firstWhere('id', $gw['device']);
        $this->assertSame(['char on', 1, true], [$row->subnets, (int) $row->has_expired, $row->error]);
        $this->assertNull($rows->firstWhere('id', $offGw['device']), 'vypnutý DHCP subnet se nezobrazí');
    }
}
