<?php

namespace Tests\Characterization\Network;

use App\Models\Setting;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace síťových rozhraní (IfaceController) před přesunem do domény
 * Network: seznam (řazení, hledání jméno/MAC), založení (MAC povinná pro
 * ethernet/wifi, unikátní), úprava (změna IP + odvozená IPv6, nová IP
 * členovi zařízení, expirace DHCP podsítí, přípojná místa), smazání
 * (IPv4/IPv6, expirace DHCP, přípojná místa), ACL.
 */
class IfacesTest extends DatabaseTestCase
{
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        Setting::set('ipv6_prefix', '2a07:9c0');
        Setting::set('ipv6_mask', '56');
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

    /** @return array{member:int, device:int} */
    private function device(): array
    {
        $m = (int) DB::table('members')->insertGetId(['name' => 'Char If', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $u = (int) DB::table('users')->insertGetId(['member_id' => $m, 'login' => 'charif_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'I', 'comment' => '']);
        DB::table('allowed_subnets_counts')->insert(['member_id' => $m, 'count' => 0]);
        return ['member' => $m, 'device' => (int) DB::table('devices')->insertGetId(['name' => 'char-if', 'type' => 1, 'user_id' => $u])];
    }

    private function subnet(string $net, int $dhcp = 1): int
    {
        return (int) DB::table('subnets')->insertGetId(['name' => 'char ' . $net, 'network_address' => $net, 'netmask' => '255.255.255.0', 'dhcp' => $dhcp, 'dhcp_expired' => 0]);
    }

    private function mac(): string
    {
        return sprintf('02:6c:%02x:%02x:%02x:%02x', random_int(0, 255), random_int(0, 255), random_int(0, 255), random_int(0, 255));
    }

    private function expired(int $subnet): int
    {
        return (int) DB::table('subnets')->where('id', $subnet)->value('dhcp_expired');
    }

    // ── seznam, založení ─────────────────────────────────────────────────────

    public function test_seznam_a_zalozeni(): void
    {
        $x = $this->device();
        $mac = $this->mac();

        $this->as()->get(route('ifaces.create', $x['device']))->assertOk()->assertViewHas('device', fn ($d) => (int) $d->id === $x['device']);
        $this->as()->post(route('ifaces.store'), ['device_id' => $x['device'], 'type' => 1, 'name' => 'eth0', 'mac' => $mac, 'comment' => 'c'])
            ->assertRedirect(route('devices.show', $x['device']))->assertSessionHas('success', 'Rozhraní bylo úspěšně přidáno.');
        $row = DB::table('ifaces')->where('mac', $mac)->first();
        $this->assertSame([1, 'eth0', 'c'], [(int) $row->type, $row->name, $row->comment]);

        $this->as()->post(route('ifaces.store'), ['device_id' => $x['device'], 'type' => 1, 'name' => 'x', 'mac' => $mac])
            ->assertSessionHasErrors(['mac' => 'Tato MAC adresa je již použita.']);
        $this->as()->post(route('ifaces.store'), ['device_id' => $x['device'], 'type' => 2, 'name' => 'x'])->assertSessionHasErrors('mac');
        $this->as()->post(route('ifaces.store'), ['device_id' => $x['device'], 'type' => 1, 'name' => 'x', 'mac' => '02-11-22-33-44-55'])
            ->assertSessionHasErrors(['mac' => 'MAC adresa musí být ve formátu aa:bb:cc:dd:ee:ff.']);
        $this->as()->post(route('ifaces.store'), ['device_id' => $x['device'], 'type' => 5, 'name' => 'vlan'])->assertSessionHasNoErrors();

        $res = $this->as()->get(route('ifaces.index', ['search' => $mac, 'sort' => 'name', 'dir' => 'desc', 'record_per_page' => 7]))->assertOk();
        $this->assertSame([(int) $row->id], collect($res->viewData('ifaces')->items())->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(['name', 'desc', 50], [$res->viewData('sort'), $res->viewData('dir'), $res->viewData('perPage')]);
        $this->as()->get(route('ifaces.show', $row->id))->assertOk()->assertViewIs('ifaces.show');
        $this->as()->get(route('ifaces.show', 999999999))->assertNotFound();
    }

    // ── úprava ───────────────────────────────────────────────────────────────

    public function test_uprava_ip_ipv6_dhcp_a_mista(): void
    {
        $x = $this->device();
        $sA = $this->subnet('10.247.1.0');
        $sB = $this->subnet('10.247.2.0');
        $sC = $this->subnet('10.247.3.0');
        $if = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'eth0', 'device_id' => $x['device'], 'mac' => $this->mac()]);
        DB::table('ip_addresses')->insert(['iface_id' => $if, 'subnet_id' => $sA, 'ip_address' => '10.247.1.5']);
        DB::table('ip6_addresses')->insert(['iface_id' => $if, 'ip_address' => '2a07:9c0:1:500::/56']);
        DB::table('allowed_subnets')->insert(['member_id' => $x['member'], 'subnet_id' => $sA, 'enabled' => 1]);

        $edit = $this->as()->get(route('ifaces.edit', $if))->assertOk();
        $this->assertContains('10.247.2.1', collect($edit->viewData('subnetData'))->firstWhere('id', $sB)['freeIps']);
        $this->assertNotContains('10.247.1.5', collect($edit->viewData('subnetData'))->firstWhere('id', $sA)['freeIps']);

        $this->as()->put(route('ifaces.update', $if), ['name' => 'eth0-new', 'comment' => 'k', 'ip_address_0' => '10.247.2.7', 'ip_subnet_0' => $sB,
            'new_ip_address' => '10.247.3.9', 'new_ip_subnet' => $sC])
            ->assertRedirect(route('devices.show', $x['device']))->assertSessionHas('success', 'Rozhraní bylo úspěšně upraveno.');

        $this->assertSame(['eth0-new', 'k'], [DB::table('ifaces')->where('id', $if)->value('name'), DB::table('ifaces')->where('id', $if)->value('comment')]);
        $ips = DB::table('ip_addresses')->where('iface_id', $if)->orderBy('id')->get();
        $this->assertSame([['10.247.2.7', $sB, null], ['10.247.3.9', $sC, $x['member']]],
            $ips->map(fn ($r) => [$r->ip_address, (int) $r->subnet_id, $r->member_id !== null ? (int) $r->member_id : null])->all());
        $this->assertSame(['2a07:9c0:2:700::/56', '2a07:9c0:3:900::/56'], DB::table('ip6_addresses')->where('iface_id', $if)->orderBy('id')->pluck('ip_address')->all());
        $this->assertSame([1, 1, 1], [$this->expired($sA), $this->expired($sB), $this->expired($sC)], 'stará i nové DHCP podsítě');
        $this->assertEqualsCanonicalizing([$sB, $sC], DB::table('allowed_subnets')->where('member_id', $x['member'])->pluck('subnet_id')->map(fn ($i) => (int) $i)->all(),
            'stará podsíť bez IP pryč, nové přibyly');
    }

    public function test_uprava_chyby(): void
    {
        $x = $this->device();
        $s = $this->subnet('10.247.4.0');
        $if = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'eth0', 'device_id' => $x['device'], 'mac' => $this->mac()]);
        DB::table('ip_addresses')->insert(['iface_id' => $if, 'subnet_id' => $s, 'ip_address' => '10.247.4.5']);
        DB::table('ip_addresses')->insert(['subnet_id' => $s, 'ip_address' => '10.247.4.6']);

        $this->as()->from(route('ifaces.edit', $if))->put(route('ifaces.update', $if), ['name' => 'x', 'ip_address_0' => '10.247.4.6', 'ip_subnet_0' => $s])
            ->assertRedirect(route('ifaces.edit', $if))->assertSessionHasErrors(['ip_address_0' => 'IP adresa 10.247.4.6 je již použita.']);
        $this->as()->from(route('ifaces.edit', $if))->put(route('ifaces.update', $if), ['name' => 'x', 'new_ip_address' => '10.247.4.5', 'new_ip_subnet' => $s])
            ->assertSessionHasErrors(['new_ip_address' => 'IP adresa 10.247.4.5 je již použita.']);
        $this->as()->put(route('ifaces.update', $if), ['name' => '', 'mac' => 'zz'])->assertSessionHasErrors(['name', 'mac']);
        $this->as()->put(route('ifaces.update', 999999999), ['name' => 'x'])->assertNotFound();
    }

    // ── smazání ──────────────────────────────────────────────────────────────

    public function test_smazani(): void
    {
        $x = $this->device();
        $s = $this->subnet('10.247.5.0');
        $if = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'eth0', 'device_id' => $x['device'], 'mac' => $this->mac()]);
        DB::table('ip_addresses')->insert(['iface_id' => $if, 'subnet_id' => $s, 'ip_address' => '10.247.5.5']);
        DB::table('ip6_addresses')->insert(['iface_id' => $if, 'ip_address' => '2a07:9c0:5:500::/56']);
        DB::table('allowed_subnets')->insert(['member_id' => $x['member'], 'subnet_id' => $s, 'enabled' => 1]);

        $this->as()->delete(route('ifaces.destroy', $if))->assertRedirect(route('devices.show', $x['device']))->assertSessionHas('success', 'Rozhraní bylo smazáno.');
        $this->assertFalse(DB::table('ifaces')->where('id', $if)->exists());
        $this->assertSame([0, 0], [DB::table('ip_addresses')->where('iface_id', $if)->count(), DB::table('ip6_addresses')->where('iface_id', $if)->count()]);
        $this->assertSame(1, $this->expired($s));
        $this->assertFalse(DB::table('allowed_subnets')->where('member_id', $x['member'])->where('subnet_id', $s)->exists());
        $this->as()->delete(route('ifaces.destroy', 999999999))->assertNotFound();
    }

    // ── ACL ──────────────────────────────────────────────────────────────────

    public function test_prava(): void
    {
        $x = $this->device();
        $if = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'eth0', 'device_id' => $x['device'], 'mac' => $this->mac()]);
        $this->acl(['view_all/Ifaces_Controller/iface', 'new_all/Ifaces_Controller/iface', 'edit_all/Ifaces_Controller/iface', 'delete_all/Ifaces_Controller/iface']);
        $this->as()->get(route('ifaces.index'))->assertForbidden();
        $this->as()->get(route('ifaces.show', $if))->assertForbidden();
        $this->as()->get(route('ifaces.create'))->assertForbidden();
        $this->as()->post(route('ifaces.store'), [])->assertForbidden();
        $this->as()->get(route('ifaces.edit', $if))->assertForbidden();
        $this->as()->put(route('ifaces.update', $if), ['name' => 'x'])->assertForbidden();
        $this->as()->delete(route('ifaces.destroy', $if))->assertForbidden();
        $this->assertTrue(DB::table('ifaces')->where('id', $if)->exists());
    }
}
