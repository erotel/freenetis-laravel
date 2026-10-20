<?php

namespace Tests\Characterization\Members;

use App\Domains\Acl\AclService;
use App\Http\Controllers\SearchController;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace globálního hledání (SearchController: stránka /search a
 * našeptávač /search/ajax) před přesunem do domén: sekce členové / uživatelé
 * / zařízení / podsítě podle práv (síťové jen se zapnutými sítěmi), víceslovné
 * dotazy, MAC v libovolném zápisu, strop 50 + celkové počty, krátký dotaz.
 */
class GlobalSearchTest extends DatabaseTestCase
{
    private int $adminId;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->tag = 'Gs' . strtolower(substr(md5(uniqid('', true)), 0, 8));
        Setting::set('networks_enabled', 1);
        $this->acl();
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function as()
    {
        return $this->actingAs(User::find($this->adminId));
    }

    /** @return array{member:int, user:int, device:int, subnet:int, mac:string, vs:string} */
    private function fixture(): array
    {
        $town = (int) DB::table('towns')->insertGetId(['town' => "Obec{$this->tag}", 'zip_code' => '79999']);
        $street = (int) DB::table('streets')->insertGetId(['street' => "Ulice{$this->tag}"]);
        $ap = (int) DB::table('address_points')->insertGetId(['town_id' => $town, 'street_id' => $street, 'street_number' => '19', 'country_id' => 1]);
        $m = (int) DB::table('members')->insertGetId(['name' => "Martin {$this->tag}", 'type' => 2, 'registration' => 1, 'locked' => 0, 'address_point_id' => $ap,
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31', 'comment' => "pozn{$this->tag}"]);
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $m, 'account_attribute_id' => 221100, 'balance' => 0, 'name' => 'c']);
        do {
            $vs = (string) random_int(70000000, 79999999);
        } while (DB::table('variable_symbols')->where('variable_symbol', $vs)->exists());
        DB::table('variable_symbols')->insert(['account_id' => $acc, 'variable_symbol' => $vs]);
        $u = (int) DB::table('users')->insertGetId(['member_id' => $m, 'login' => "login{$this->tag}", 'password' => 'x', 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'Jan', 'surname' => "Prijmeni{$this->tag}", 'comment' => '']);
        $c = (int) DB::table('contacts')->insertGetId(['type' => 20, 'value' => "{$this->tag}@example.test"]);
        DB::table('users_contacts')->insert(['user_id' => $u, 'contact_id' => $c]);
        $mac = sprintf('02:6B:%02X:%02X:%02X:%02X', random_int(0, 255), random_int(0, 255), random_int(0, 255), random_int(0, 255));
        $d = (int) DB::table('devices')->insertGetId(['name' => "ap-{$this->tag}", 'type' => 1, 'user_id' => $u, 'address_point_id' => $ap]);
        $i = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'e', 'device_id' => $d, 'mac' => $mac]);
        DB::table('ip_addresses')->insert(['iface_id' => $i, 'ip_address' => '10.248.77.' . random_int(1, 250)]);
        $s = (int) DB::table('subnets')->insertGetId(['name' => "Podsit {$this->tag}", 'network_address' => '10.248.77.0', 'netmask' => '255.255.255.0']);
        return ['member' => $m, 'user' => $u, 'device' => $d, 'subnet' => $s, 'mac' => $mac, 'vs' => $vs];
    }

    /** @return array<string, int[]> sekce => id */
    private function sections(string $q): array
    {
        $res = $this->as()->get(route('search', ['q' => $q]))->assertOk()->assertViewIs('search.index');
        return collect($res->viewData('results'))->map(fn ($rows) => collect($rows)->pluck('id')->map(fn ($i) => (int) $i)->unique()->values()->all())->all();
    }

    public function test_stranka_sekce_a_dotazy(): void
    {
        $x = $this->fixture();

        $all = $this->sections($this->tag);
        $this->assertSame(['members', 'users', 'devices', 'subnets'], array_keys($all));
        $this->assertSame([[$x['member']], [$x['user']], [$x['device']], [$x['subnet']]], array_values($all));

        $res = $this->as()->get(route('search', ['q' => $this->tag]));
        $this->assertSame(['members' => 1, 'users' => 1, 'devices' => 1, 'subnets' => 1], $res->viewData('totals'));
        $this->assertSame($this->tag, $res->viewData('query'));
        $mrow = $res->viewData('results')['members'][0];
        $this->assertSame(["Martin {$this->tag}", "Ulice{$this->tag}", '19', "Obec{$this->tag}", '79999', $x['vs']],
            [$mrow->name, $mrow->street, $mrow->street_number, $mrow->town, $mrow->zip_code, $mrow->variable_symbol]);
        $urow = $res->viewData('results')['users'][0];
        $this->assertSame(["login{$this->tag}", $x['member']], [$urow->login, (int) $urow->member_id]);
        $drow = $res->viewData('results')['devices'][0];
        $this->assertSame([$x['mac'], $x['member']], [$drow->mac, (int) $drow->member_id]);

        $this->assertSame([$x['member']], $this->sections($x['vs'])['members'], 'VS');
        $this->assertSame([$x['member']], $this->sections("pozn{$this->tag}")['members'], 'poznámka');
        $this->assertSame([$x['member']], $this->sections("{$this->tag} Martin Obec{$this->tag}")['members'], 'víc slov přes jméno+adresu');
        $this->assertSame([$x['user']], $this->sections("Prijmeni{$this->tag} Jan")['users'], 'víc slov přes jméno uživatele');
        $this->assertSame([$x['user']], $this->sections("{$this->tag}@example")['users'], 'kontakt');
        $this->assertSame([$x['device']], $this->sections(strtolower(str_replace(':', '-', $x['mac'])))['devices'], 'MAC s pomlčkami');
        $this->assertSame([$x['device']], $this->sections(strtolower(str_replace(':', '', $x['mac'])))['devices'], 'MAC bez oddělovačů');
        $this->assertSame([$x['device']], $this->sections("ap-{$this->tag} Ulice{$this->tag}")['devices'], 'zařízení víc slov');

        $short = $this->as()->get(route('search', ['q' => 'a']));
        $this->assertSame([[], []], [$short->viewData('results'), $short->viewData('totals')]);
    }

    public function test_prava_a_site(): void
    {
        $x = $this->fixture();
        Setting::set('networks_enabled', 0);
        $this->assertSame(['members', 'users'], array_keys($this->sections($this->tag)));
        Setting::set('networks_enabled', 1);
        $this->acl(['view_all/Members_Controller/members', 'view_all/Devices_Controller/devices']);
        $this->assertSame(['users', 'subnets'], array_keys($this->sections($this->tag)));
        $this->acl(['view_all/Users_Controller/users', 'view_all/Subnets_Controller/subnet']);
        $this->assertSame(['members', 'devices'], array_keys($this->sections($this->tag)));
    }

    public function test_strop_a_celkem(): void
    {
        $town = (int) DB::table('towns')->insertGetId(['town' => "Mesto{$this->tag}", 'zip_code' => '1']);
        $ap = (int) DB::table('address_points')->insertGetId(['town_id' => $town, 'street_number' => '1', 'country_id' => 1]);
        for ($i = 0; $i < 52; $i++) {
            DB::table('members')->insert(['name' => "Hromadny {$this->tag} {$i}", 'type' => 2, 'registration' => 1, 'locked' => 0, 'address_point_id' => $ap,
                'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        }
        $res = $this->as()->get(route('search', ['q' => "Hromadny {$this->tag}"]));
        $this->assertCount(SearchController::SECTION_LIMIT, $res->viewData('results')['members']);
        $this->assertSame(52, $res->viewData('totals')['members']);
    }

    public function test_naseptavac(): void
    {
        $x = $this->fixture();
        $json = $this->as()->getJson(route('search.ajax', ['q' => $this->tag]))->assertOk()->json();
        $this->assertSame([
            ['url' => route('members.show', $x['member']), 'title' => \App\Helpers\MemberType::label(2) . " Martin {$this->tag}", 'detail' => 'VS: ' . $x['vs']],
            ['url' => route('members.show', $x['member']), 'title' => "Uživatel login{$this->tag} (#{$x['user']})", 'detail' => "{$this->tag}@example.test"],
        ], array_slice($json, 0, 2));
        $dev = $json[2];
        $this->assertSame([route('devices.show', $x['device']), "Zařízení ap-{$this->tag}"], [$dev['url'], $dev['title']]);
        $this->assertStringEndsWith(" / {$x['mac']} — Martin {$this->tag}", $dev['detail']);

        $this->assertSame([], $this->as()->getJson(route('search.ajax', ['q' => 'ab']))->json(), 'krátký dotaz');
        $mac = $this->as()->getJson(route('search.ajax', ['q' => strtolower(str_replace(':', '.', $x['mac']))]))->json();
        $this->assertSame(route('devices.show', $x['device']), $mac[0]['url'] ?? null, 'MAC s tečkami');

        $this->acl(['view_all/Members_Controller/members', 'view_all/Users_Controller/users']);
        $this->assertSame([route('devices.show', $x['device'])], array_column($this->as()->getJson(route('search.ajax', ['q' => $this->tag]))->json(), 'url'));
    }

    public function test_normalizace_mac(): void
    {
        foreach (['50:91:e3:11:29:ad', '50-91-e3-11-29-ad', '5091.e311.29ad', '5091e31129ad', ' 50 91 E3 11 29 AD '] as $in) {
            $this->assertSame('50:91:E3:11:29:AD', SearchController::normalizeMac($in), $in);
        }
        $this->assertNull(SearchController::normalizeMac('50:91:e3'));
        $this->assertNull(SearchController::normalizeMac('zz:91:e3:11:29:ad'));
    }
}
