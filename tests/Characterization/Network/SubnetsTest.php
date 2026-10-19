<?php

namespace Tests\Characterization\Network;

use App\Domains\Access\Events\IpoeReservationsChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Characterization\Catalog\CatalogTestCase;

/**
 * Charakterizace podsítí (SubnetController) před přesunem do domény Network:
 * seznam (hledání, filtr, řazení, počty IP), detail (IP podle čísla, sdílené
 * line-id), založení / úprava (síť odpovídá masce, jedinečnost, jemná práva
 * dhcp/dns/qos/ipoe, reset dhcp_expired, událost IPoE), smazání, ACL.
 */
class SubnetsTest extends CatalogTestCase
{
    private string $net;

    protected function setUp(): void
    {
        parent::setUp();
        do {
            $this->net = '10.243.' . random_int(1, 250);
        } while (DB::table('subnets')->where('network_address', "{$this->net}.0")->exists());
    }

    private function subnet(array $over = []): int
    {
        return (int) DB::table('subnets')->insertGetId($over + ['name' => "Char {$this->tag}", 'network_address' => "{$this->net}.0", 'netmask' => '255.255.255.0',
            'dhcp' => 0, 'dhcp_expired' => 0, 'dns' => 0, 'qos' => 0, 'ipoe' => 0]);
    }

    private function row(int $id): array
    {
        $r = DB::table('subnets')->where('id', $id)->first(['name', 'network_address', 'netmask', 'dhcp', 'dhcp_expired', 'dns', 'qos', 'ipoe', 'redirect']);
        return array_map(fn ($v) => is_numeric($v) && !str_contains((string) $v, '.') ? (int) $v : $v, (array) $r);
    }

    public function test_seznam(): void
    {
        $a = $this->subnet();
        $b = $this->subnet(['name' => "Char {$this->tag} b", 'network_address' => "{$this->net}.128", 'netmask' => '255.255.255.128', 'dhcp' => 1]);
        DB::table('ip_addresses')->insert([['ip_address' => "{$this->net}.5", 'subnet_id' => $a], ['ip_address' => "{$this->net}.6", 'subnet_id' => $a]]);

        $res = $this->as()->get(route('subnets.index', ['search' => $this->tag, 'sort' => 'name', 'dir' => 'desc', 'record_per_page' => 100]))->assertOk();
        $this->assertSame([[$b, 0], [$a, 2]], collect($res->viewData('subnets')->items())->map(fn ($s) => [(int) $s->id, (int) $s->ip_addresses_count])->all());
        $this->assertSame(['name', 'desc', 100, $this->tag], [$res->viewData('sort'), $res->viewData('dir'), $res->viewData('perPage'), $res->viewData('search')]);
        $this->assertTrue($res->viewData('showDhcp') && $res->viewData('showDns') && $res->viewData('showQos') && $res->viewData('canNew'));
        $this->assertSame([$b], collect($this->as()->get(route('subnets.index', ['search' => "{$this->net}.1"]))->viewData('subnets')->items())->pluck('id')->map(fn ($i) => (int) $i)->all());

        $f = ['search' => $this->tag, 'filters' => [['active' => 1, 'field' => 'dhcp', 'op' => 'eq', 'value' => '1']]];
        $this->assertSame([$b], collect($this->as()->get(route('subnets.index', $f))->viewData('subnets')->items())->pluck('id')->map(fn ($i) => (int) $i)->all());
        $def = $this->as()->get(route('subnets.index', ['sort' => 'x', 'record_per_page' => 7]));
        $this->assertSame(['id', 'asc', 50], [$def->viewData('sort'), $def->viewData('dir'), $def->viewData('perPage')]);
    }

    public function test_detail(): void
    {
        $id = $this->subnet();
        $dev = (int) DB::table('devices')->insertGetId(['name' => 'char-sub', 'type' => 1, 'user_id' => $this->adminId]);
        $mk = fn (string $mac) => (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'e', 'device_id' => $dev, 'mac' => $mac]);
        $i1 = $mk('aa:00:00:00:00:01');
        $i2 = $mk('aa:00:00:00:00:02');
        DB::table('ip_addresses')->insert([
            ['ip_address' => "{$this->net}.20", 'subnet_id' => $id, 'iface_id' => $i1],
            ['ip_address' => "{$this->net}.125", 'subnet_id' => $id, 'iface_id' => null],
            ['ip_address' => "{$this->net}.16", 'subnet_id' => $id, 'iface_id' => $i2],
        ]);
        $hex = bin2hex("char-{$this->tag}");
        DB::table('line_id_seen')->insert([
            ['circuit_id_hex' => "0x{$hex}", 'mac' => 'AA:00:00:00:00:01'],
            ['circuit_id_hex' => "0x{$hex}", 'mac' => 'BB:00:00:00:00:09'],
            ['circuit_id_hex' => '0xab' . $hex, 'mac' => 'AA:00:00:00:00:02'],
        ]);

        $res = $this->as()->get(route('subnets.show', $id))->assertOk()->assertViewIs('subnets.show');
        $this->assertSame(['16', '20', '125'], $res->viewData('subnet')->ipAddresses->map(fn ($ip) => explode('.', $ip->ip_address)[3])->all(), 'číselně');
        $this->assertSame(['AA:00:00:00:00:01' => "char-{$this->tag}"], $res->viewData('sharedLineIds'), 'jen sdílený circuit-id, čitelně');
        $this->as()->get(route('subnets.show', 999999999))->assertNotFound();
    }

    public function test_zalozeni_uprava_smazani(): void
    {
        $this->as()->get(route('subnets.create'))->assertOk()->assertViewHas('canSetDhcp', true);
        $this->as()->post(route('subnets.store'), ['name' => "Char {$this->tag}", 'network_address' => "{$this->net}.0", 'netmask' => '255.255.255.0', 'dhcp' => '1', 'dns' => '1', 'redirect' => '1']);
        $id = (int) DB::table('subnets')->where('network_address', "{$this->net}.0")->value('id');
        $this->assertSame(['name' => "Char {$this->tag}", 'network_address' => "{$this->net}.0", 'netmask' => '255.255.255.0', 'dhcp' => 1, 'dhcp_expired' => 0, 'dns' => 1, 'qos' => 0, 'ipoe' => 0, 'redirect' => 1], $this->row($id));

        $this->as()->post(route('subnets.store'), ['network_address' => "{$this->net}.0", 'netmask' => '255.255.255.0'])->assertSessionHasErrors(['network_address' => 'Tento subnet (síť + maska) již existuje.']);
        $this->as()->post(route('subnets.store'), ['network_address' => "{$this->net}.5", 'netmask' => '255.255.255.0'])->assertSessionHasErrors(['network_address' => 'Síťová adresa neodpovídá zadané masce.']);
        $this->as()->post(route('subnets.store'), ['network_address' => 'x', 'netmask' => '255.255.255.1'])->assertSessionHasErrors(['network_address' => 'Neplatný formát síťové adresy.', 'netmask' => 'Neplatný formát masky sítě.']);
        $this->as()->post(route('subnets.store'), ['network_address' => "{$this->net}.0", 'netmask' => '255.255.255.128'])->assertSessionHasNoErrors();

        $this->as()->get(route('subnets.edit', $id))->assertOk()->assertViewHas('subnet', fn ($s) => (int) $s->id === $id);
        DB::table('subnets')->where('id', $id)->update(['dhcp_expired' => 1]);
        Event::fake([IpoeReservationsChanged::class]);
        // neposlané příznaky se nemění
        $this->as()->put(route('subnets.update', $id), ['name' => 'Upr', 'network_address' => "{$this->net}.0", 'netmask' => '255.255.255.0'])
            ->assertRedirect(route('subnets.show', $id))->assertSessionHas('success', 'Subnet byl úspěšně upraven.');
        $this->assertSame(['Upr', 1, 1, 1], [$this->row($id)['name'], $this->row($id)['dhcp'], $this->row($id)['dhcp_expired'], $this->row($id)['dns']]);
        $this->as()->put(route('subnets.update', $id), ['name' => 'Upr', 'network_address' => "{$this->net}.0", 'netmask' => '255.255.255.0', 'dhcp' => '0', 'dns' => '0']);
        $this->assertSame([0, 0, 0], [$this->row($id)['dhcp'], $this->row($id)['dhcp_expired'], $this->row($id)['dns']], 'vypnuté DHCP resetuje dhcp_expired');
        Event::assertNotDispatched(IpoeReservationsChanged::class);
        $this->as()->put(route('subnets.update', $id), ['network_address' => "{$this->net}.0", 'netmask' => '255.255.255.0', 'dhcp' => '1', 'ipoe' => '1']);
        Event::assertDispatched(IpoeReservationsChanged::class);
        $this->as()->get(route('subnets.edit', 999999999))->assertNotFound();
        $this->as()->put(route('subnets.update', 999999999), [])->assertNotFound();

        DB::table('ip_addresses')->insert(['ip_address' => "{$this->net}.9", 'subnet_id' => $id]);
        $this->as()->from(route('subnets.show', $id))->delete(route('subnets.destroy', $id))
            ->assertRedirect(route('subnets.show', $id))->assertSessionHas('error', 'Subnet nelze smazat, obsahuje IP adresy.');
        DB::table('ip_addresses')->where('subnet_id', $id)->delete();
        $this->as()->delete(route('subnets.destroy', $id))->assertRedirect(route('subnets.index'))->assertSessionHas('success', 'Subnet byl úspěšně smazán.');
        $this->assertFalse(DB::table('subnets')->where('id', $id)->exists());
        $this->as()->delete(route('subnets.destroy', $id))->assertNotFound();
    }

    /** Jemná práva dhcp/dns/qos (+ ipoe pod dhcp): bez práva se pole neuloží / nezmění. */
    public function test_jemna_prava(): void
    {
        $this->acl(['new_all/Subnets_Controller/dhcp', 'new_all/Subnets_Controller/qos', 'edit_all/Subnets_Controller/dns']);
        $this->as()->post(route('subnets.store'), ['network_address' => "{$this->net}.0", 'netmask' => '255.255.255.0', 'dhcp' => '1', 'ipoe' => '1', 'dns' => '1', 'qos' => '1']);
        $id = (int) DB::table('subnets')->where('network_address', "{$this->net}.0")->value('id');
        $this->assertSame([0, 0, 1, 0], [$this->row($id)['dhcp'], $this->row($id)['ipoe'], $this->row($id)['dns'], $this->row($id)['qos']]);
        $this->as()->put(route('subnets.update', $id), ['network_address' => "{$this->net}.0", 'netmask' => '255.255.255.0', 'dhcp' => '1', 'dns' => '0', 'qos' => '1']);
        $this->assertSame([1, 1, 1], [$this->row($id)['dhcp'], $this->row($id)['dns'], $this->row($id)['qos']]);
    }

    public function test_prava(): void
    {
        $id = $this->subnet();
        $this->acl(['view_all/Subnets_Controller/subnet', 'new_all/Subnets_Controller/subnet', 'edit_all/Subnets_Controller/subnet', 'delete_all/Subnets_Controller/subnet']);
        foreach ([route('subnets.index'), route('subnets.show', $id), route('subnets.create'), route('subnets.edit', $id)] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        $this->as()->post(route('subnets.store'), [])->assertForbidden();
        $this->as()->put(route('subnets.update', $id), [])->assertForbidden();
        $this->as()->delete(route('subnets.destroy', $id))->assertForbidden();
        $this->assertTrue(DB::table('subnets')->where('id', $id)->exists());
    }
}
