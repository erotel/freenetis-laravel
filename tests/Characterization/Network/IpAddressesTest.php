<?php

namespace Tests\Characterization\Network;

use App\Models\Setting;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace IP adres (IpAddressController) před přesunem do domény
 * Network: seznam (hledání, řazení), detail, založení (vlastník u IP na
 * rozhraní jde ze zařízení, odvozená IPv6, expirace DHCP podsítě, přípojné
 * místo, jediná gateway v podsíti), úprava (přesun mezi podsítěmi), smazání,
 * ACL.
 */
class IpAddressesTest extends DatabaseTestCase
{
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        Setting::set('ipv6_prefix', '2a07:9c0');
        Setting::set('ipv6_mask', '56');
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $this->deny ?? [], true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private array $deny = [];

    private function as()
    {
        return $this->actingAs(User::find($this->adminId));
    }

    /** @return array{member:int, iface:int} */
    private function iface(): array
    {
        $m = (int) DB::table('members')->insertGetId(['name' => 'Char IP', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $u = (int) DB::table('users')->insertGetId(['member_id' => $m, 'login' => 'charip_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'I', 'comment' => '']);
        DB::table('allowed_subnets_counts')->insert(['member_id' => $m, 'count' => 0]);
        $d = (int) DB::table('devices')->insertGetId(['name' => 'char-ip', 'type' => 1, 'user_id' => $u]);
        return ['member' => $m, 'iface' => (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'e', 'device_id' => $d])];
    }

    private function subnet(string $net): int
    {
        return (int) DB::table('subnets')->insertGetId(['name' => 'char ' . $net, 'network_address' => $net, 'netmask' => '255.255.255.0', 'dhcp' => 1, 'dhcp_expired' => 0]);
    }

    private function ip(string $addr): object
    {
        return DB::table('ip_addresses')->where('ip_address', $addr)->orderByDesc('id')->first();
    }

    public function test_zalozeni_uprava_smazani(): void
    {
        $x = $this->iface();
        $a = $this->subnet('10.240.1.0');
        $b = $this->subnet('10.240.2.0');

        $this->as()->get(route('ip_addresses.create', ['iface_id' => $x['iface']]))->assertOk()->assertViewHas('preselectedMemberId', $x['member']);
        $this->as()->post(route('ip_addresses.store'), ['iface_id' => $x['iface'], 'subnet_id' => $a, 'member_id' => 1, 'ip_address' => '10.240.1.5'])
            ->assertSessionHas('success', 'IP adresa byla úspěšně přidána.');
        $ip = $this->ip('10.240.1.5');
        $this->assertNull($ip->member_id, 'u IP na rozhraní vlastník ze zařízení');
        $this->assertSame(['2a07:9c0:1:500::/56'], DB::table('ip6_addresses')->where('iface_id', $x['iface'])->pluck('ip_address')->all());
        $this->assertSame(1, (int) DB::table('subnets')->where('id', $a)->value('dhcp_expired'));
        $this->assertTrue(DB::table('allowed_subnets')->where('member_id', $x['member'])->where('subnet_id', $a)->exists());

        $this->as()->post(route('ip_addresses.store'), ['subnet_id' => $a, 'ip_address' => '10.240.1.5'])->assertSessionHasErrors(['ip_address' => 'Tato IP adresa je již v daném subnetu použita.']);
        $this->as()->post(route('ip_addresses.store'), ['subnet_id' => $a, 'ip_address' => 'abc'])->assertSessionHasErrors(['ip_address' => 'Neplatný formát IP adresy.']);
        $this->as()->post(route('ip_addresses.store'), ['subnet_id' => $a, 'ip_address' => '999.1.1.1'])->assertSessionHasErrors(['ip_address' => 'Neplatný formát IP adresy.']);
        // IP mimo zvolenou podsíť (dřív prošla — DHCP ji pak rozdával v cizí síti)
        $this->as()->post(route('ip_addresses.store'), ['subnet_id' => $a, 'ip_address' => '10.240.2.50'])
            ->assertSessionHasErrors(['ip_address' => 'IP adresa 10.240.2.50 neleží v podsíti 10.240.1.0/24.']);
        $this->as()->post(route('ip_addresses.store'), ['subnet_id' => $a, 'ip_address' => '10.240.1.1', 'gateway' => 1])->assertSessionHasNoErrors();
        $this->as()->from(route('ip_addresses.create'))->post(route('ip_addresses.store'), ['subnet_id' => $a, 'ip_address' => '10.240.1.2', 'gateway' => 1])
            ->assertSessionHasErrors(['gateway' => 'V tomto subnetu již existuje gateway.']);
        $direct = $this->ip('10.240.1.1');
        $this->as()->post(route('ip_addresses.store'), ['subnet_id' => $a, 'member_id' => $x['member'], 'ip_address' => '10.240.1.9'])->assertSessionHasNoErrors();
        $this->assertSame($x['member'], (int) $this->ip('10.240.1.9')->member_id, 'bez rozhraní vlastník z formuláře');

        DB::table('subnets')->whereIn('id', [$a, $b])->update(['dhcp_expired' => 0]);
        $this->as()->get(route('ip_addresses.edit', $ip->id))->assertOk();
        $this->as()->put(route('ip_addresses.update', $ip->id), ['iface_id' => $x['iface'], 'subnet_id' => $b, 'ip_address' => '10.240.2.7'])
            ->assertRedirect(route('ip_addresses.show', $ip->id))->assertSessionHas('success', 'IP adresa byla úspěšně upravena.');
        $this->assertSame(['2a07:9c0:2:700::/56'], DB::table('ip6_addresses')->where('iface_id', $x['iface'])->pluck('ip_address')->all());
        $this->assertSame([1, 1], [(int) DB::table('subnets')->where('id', $a)->value('dhcp_expired'), (int) DB::table('subnets')->where('id', $b)->value('dhcp_expired')]);
        $this->assertTrue(DB::table('allowed_subnets')->where('member_id', $x['member'])->where('subnet_id', $b)->exists());
        // místo v A zůstává — člen tam má přímou IP .9 (vlastník IP = přímý člen, jinak zařízení)
        $this->assertTrue(DB::table('allowed_subnets')->where('member_id', $x['member'])->where('subnet_id', $a)->exists());

        $show = $this->as()->get(route('ip_addresses.show', $ip->id))->assertOk();
        $this->assertSame($ip->id, (int) $show->viewData('ip')->id);
        $idx = collect($this->as()->get(route('ip_addresses.index', ['search' => '10.240.2.', 'sort' => 'ip_address']))->viewData('ipAddresses')->items());
        $this->assertSame([$ip->id], $idx->pluck('id')->map(fn ($i) => (int) $i)->all());

        $this->as()->delete(route('ip_addresses.destroy', $ip->id))->assertRedirect(route('ip_addresses.index'))->assertSessionHas('success', 'IP adresa byla úspěšně smazána.');
        $this->assertFalse(DB::table('ip_addresses')->where('id', $ip->id)->exists());
        $this->assertSame([], DB::table('ip6_addresses')->where('iface_id', $x['iface'])->pluck('ip_address')->all());
        $this->assertFalse(DB::table('allowed_subnets')->where('member_id', $x['member'])->where('subnet_id', $b)->exists());
        $this->as()->delete(route('ip_addresses.destroy', 999999999))->assertNotFound();
        $this->as()->get(route('ip_addresses.show', 999999999))->assertNotFound();
        $this->assertNotNull($direct);
    }

    public function test_prava(): void
    {
        $id = (int) DB::table('ip_addresses')->insertGetId(['ip_address' => '10.240.9.9']);
        $this->deny = ['view_all/Ip_addresses_Controller/ip_address', 'new_all/Ip_addresses_Controller/ip_address', 'edit_all/Ip_addresses_Controller/ip_address', 'delete_all/Ip_addresses_Controller/ip_address'];
        foreach ([route('ip_addresses.show', $id), route('ip_addresses.create'), route('ip_addresses.edit', $id)] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        $this->as()->post(route('ip_addresses.store'), [])->assertForbidden();
        $this->as()->put(route('ip_addresses.update', $id), [])->assertForbidden();
        $this->as()->delete(route('ip_addresses.destroy', $id))->assertForbidden();
    }

    /**
     * Přímo přiřazená IP (bez rozhraní): smazání nevezme IPv6 zařízení se stejnou
     * IPv4, přípojné místo vlastníka odejde až s poslední jeho IP v podsíti;
     * přidání přímé IP místo založí.
     */
    public function test_prima_ip_bez_rozhrani(): void
    {
        $x = $this->iface();
        $a = $this->subnet('10.240.3.0');
        DB::table('allowed_subnets')->where('member_id', $x['member'])->delete();

        // zařízení člena má .5 (s IPv6), stejná IPv4 je navíc přímo u člena (legacy duplicita)
        $this->as()->post(route('ip_addresses.store'), ['iface_id' => $x['iface'], 'subnet_id' => $a, 'ip_address' => '10.240.3.5'])->assertSessionHasNoErrors();
        $dup = (int) DB::table('ip_addresses')->insertGetId(['ip_address' => '10.240.3.5', 'subnet_id' => $a, 'member_id' => $x['member']]);
        $this->as()->delete(route('ip_addresses.destroy', $dup))->assertRedirect(route('ip_addresses.index'));
        $this->assertSame(['2a07:9c0:3:500::/56'], DB::table('ip6_addresses')->where('iface_id', $x['iface'])->pluck('ip_address')->all(), 'IPv6 zařízení zůstává');

        // přímé IP bez rozhraní: přidání založí místo, smazání jedné ho nechá, poslední ho vezme
        $b = $this->subnet('10.240.4.0');
        $this->as()->post(route('ip_addresses.store'), ['subnet_id' => $b, 'member_id' => $x['member'], 'ip_address' => '10.240.4.1'])->assertSessionHasNoErrors();
        $this->as()->post(route('ip_addresses.store'), ['subnet_id' => $b, 'member_id' => $x['member'], 'ip_address' => '10.240.4.2'])->assertSessionHasNoErrors();
        $place = fn () => DB::table('allowed_subnets')->where('member_id', $x['member'])->where('subnet_id', $b)->exists();
        $this->assertTrue($place());
        $this->as()->delete(route('ip_addresses.destroy', $this->ip('10.240.4.1')->id));
        $this->assertTrue($place(), 'zbývá přímá IP .2');
        $this->as()->delete(route('ip_addresses.destroy', $this->ip('10.240.4.2')->id));
        $this->assertFalse($place());
    }
}
