<?php

namespace Tests\Feature\Members;

use App\Models\MemberLanAccess;
use App\Models\Setting;
use App\Models\User;
use App\Services\AclService;
use App\Services\LanIsolationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\DatabaseTestCase;

/**
 * Izolace klientů od vnitřní sítě (LanIsolationService + MemberLanAccessController):
 *  - validace/normalizace cílů (IP, CIDR, zarovnání, jen uvnitř vnitřní sítě),
 *  - RouterOS sekce DHCP exportu: address-listy, chain fn-lan, jump, úklid,
 *  - správa výjimek u člena jen s právem Members_Controller#lan_access (server-side),
 *  - změna výjimky označí DHCP subnety člena jako změněné (router si ji stáhne).
 */
class MemberLanAccessTest extends DatabaseTestCase
{
    private LanIsolationService $lan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lan = app(LanIsolationService::class);
        Setting::set('lan_isolation_network', '10.133.0.0/16');
        Setting::set('lan_isolation_public', "10.133.37.37\n10.133.37.38");
        Setting::set('lan_isolation_infra_members', '');
        Setting::set('lan_isolation_devices', '');
    }

    private function grantAcl(bool $allowLan): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(
            fn ($userId, $aco, $section, $value) => $value === 'lan_access' ? $allowLan : true
        );
    }

    /** Člen s alespoň jednou IP na zařízení v DHCP subnetu (+ ta IP a subnet). */
    private function memberWithDhcpIp(): object
    {
        $row = DB::table('ip_addresses as ip')
            ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->join('subnets as s', 's.id', '=', 'ip.subnet_id')
            ->where('s.dhcp', 1)
            ->where('ip.gateway', 0)
            ->where('ip.ip_address', 'like', '10.133.%')
            ->select('u.member_id', 'ip.ip_address', 'ip.subnet_id', 's.network_address', 's.netmask')
            ->first();
        if (!$row) {
            $this->markTestSkipped('žádný člen s IP v DHCP subnetu');
        }
        return $row;
    }

    private function server(object $row): array
    {
        $bits = substr_count(sprintf('%032b', ip2long($row->netmask)), '1');
        return ['subnet_id' => (int) $row->subnet_id, 'cidr' => "{$row->network_address}/{$bits}"];
    }

    // ── normalizace ──────────────────────────────────────────────────────────

    public function test_normalizace_ip_a_rozsahu(): void
    {
        $this->assertSame('10.133.139.65', $this->lan->normalizeDestination(' 10.133.139.65 '));
        $this->assertSame('10.133.139.65', $this->lan->normalizeDestination('10.133.139.65/32'));
        $this->assertSame('10.133.230.0/24', $this->lan->normalizeDestination('10.133.230.0/24'));
        $this->assertSame('10.133.0.0/16', $this->lan->normalizeDestination('10.133.0.0/16'));
    }

    public function test_nezarovnany_rozsah_se_odmitne_s_napovedou(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('10.133.139.0/24');
        $this->lan->normalizeDestination('10.133.139.5/24');
    }

    public function test_cil_mimo_vnitrni_sit_se_odmitne(): void
    {
        foreach (['8.8.8.8', '10.134.0.1', '10.0.0.0/8', 'nesmysl', '10.133.300.1', '10.133.0.0/33'] as $bad) {
            try {
                $this->lan->normalizeDestination($bad);
                $this->fail("{$bad} měl být odmítnut");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── export ───────────────────────────────────────────────────────────────

    public function test_vypnute_zarizeni_dostane_jen_uklid(): void
    {
        $out = $this->lan->renderMikrotik(999999, [], null);
        $this->assertStringContainsString('/ip firewall filter remove [/ip firewall filter find comment~"^fn-lan"]', $out);
        $this->assertStringContainsString('/ip firewall address-list remove [/ip firewall address-list find list~"^fn-lan"]', $out);
        $this->assertStringNotContainsString(' add ', $out);
    }

    public function test_relay_rezim_nic(): void
    {
        Setting::set('lan_isolation_devices', '999999');
        $this->assertSame('', $this->lan->renderMikrotik(999999, [], 'vlan1010'));
    }

    public function test_zapnute_zarizeni_generuje_izolaci_a_vyjimky_clena(): void
    {
        $row = $this->memberWithDhcpIp();
        Setting::set('lan_isolation_devices', "999999");
        MemberLanAccess::create(['member_id' => $row->member_id, 'destination' => '10.133.230.0/24']);
        MemberLanAccess::create(['member_id' => $row->member_id, 'destination' => '10.133.139.65']);

        $srv = $this->server($row);
        $out = $this->lan->renderMikrotik(999999, [$srv], null);
        $m   = (int) $row->member_id;

        // zákaznické subnety + veřejné služby
        $this->assertStringContainsString("add list=\"fn-lan-customers\" address={$srv['cidr']}", $out);
        $this->assertStringContainsString('add list="fn-lan-public" address=10.133.37.37', $out);
        // výjimky člena: zdroj = jeho IP, cíl = povolené rozsahy
        $this->assertStringContainsString("add list=\"fn-lan-m{$m}-src\" address={$row->ip_address}", $out);
        $this->assertStringContainsString("add list=\"fn-lan-m{$m}-dst\" address=10.133.230.0/24", $out);
        $this->assertStringContainsString("add list=\"fn-lan-m{$m}-dst\" address=10.133.139.65", $out);
        // chain: drop + accepty před něj + per-člen pravidlo
        $this->assertStringContainsString('add chain=fn-lan action=drop comment="fn-lan:drop"', $out);
        $this->assertStringContainsString('connection-state=established,related action=accept comment="fn-lan:est"', $out);
        $this->assertStringContainsString("src-address-list=fn-lan-m{$m}-src dst-address-list=fn-lan-m{$m}-dst action=accept comment=\"fn-lan:m{$m}\"", $out);
        // úklid zastaralých položek podle generace + pravidel bývalých výjimek
        $this->assertMatchesRegularExpression('#address-list remove \[/ip firewall address-list find list~"\^fn-lan" comment!="fn-lan \d{14}-[0-9a-f]{6}"\]#', $out);
        $this->assertStringContainsString("comment~\"^fn-lan:m\" comment!=\"fn-lan:m{$m}\"]", $out);
        // jump z forward až nakonec, podle zdrojového subnetu
        $this->assertStringContainsString('chain=forward src-address-list=fn-lan-customers dst-address=10.133.0.0/16 action=jump jump-target=fn-lan', $out);
        $this->assertGreaterThan(strrpos($out, 'fn-lan:drop'), strrpos($out, 'fn-lan:jump'));
    }

    public function test_infrastrukturni_clen_ma_celou_sit(): void
    {
        $row = $this->memberWithDhcpIp();
        Setting::set('lan_isolation_devices', '999999');
        Setting::set('lan_isolation_infra_members', (string) $row->member_id);

        $out = $this->lan->renderMikrotik(999999, [$this->server($row)], null);
        $this->assertStringContainsString("add list=\"fn-lan-m{$row->member_id}-dst\" address=10.133.0.0/16", $out);
    }

    public function test_clen_bez_ip_na_routeru_nema_pravidlo(): void
    {
        $row = $this->memberWithDhcpIp();
        Setting::set('lan_isolation_devices', '999999');
        MemberLanAccess::create(['member_id' => $row->member_id, 'destination' => '10.133.230.0/24']);

        // Subnet, kde člen nic nemá → žádný list ani pravidlo pro něj.
        $out = $this->lan->renderMikrotik(999999, [['subnet_id' => -1, 'cidr' => '10.133.250.0/24']], null);
        $this->assertStringNotContainsString("fn-lan-m{$row->member_id}", $out);
        $this->assertStringContainsString('comment~"^fn-lan:m"]', $out, 'bez výjimek se smažou všechna per-člen pravidla');
    }

    // ── správa u člena ───────────────────────────────────────────────────────

    public function test_pridani_vyjimky_ulozi_normalizovane_a_oznaci_subnety(): void
    {
        $row = $this->memberWithDhcpIp();
        $this->grantAcl(true);
        DB::table('subnets')->where('id', $row->subnet_id)->update(['dhcp_expired' => 0]);

        $this->actingAs(User::find(1))
            ->post(route('member_lan_access.store', $row->member_id), [
                'destinations' => "10.133.139.65, 10.133.230.0/24\n10.133.139.65/32",
                'comment'      => 'test',
            ])->assertRedirect(route('members.show', $row->member_id));

        $this->assertEqualsCanonicalizing(
            ['10.133.139.65', '10.133.230.0/24'],
            MemberLanAccess::where('member_id', $row->member_id)->pluck('destination')->all()
        );
        $this->assertSame(1, (int) DB::table('subnets')->where('id', $row->subnet_id)->value('dhcp_expired'));
    }

    public function test_neplatny_cil_nic_neulozi(): void
    {
        $row = $this->memberWithDhcpIp();
        $this->grantAcl(true);

        $this->actingAs(User::find(1))
            ->post(route('member_lan_access.store', $row->member_id), ['destinations' => '10.133.230.0/24, 8.8.8.8'])
            ->assertSessionHasErrors('destinations');

        $this->assertSame(0, MemberLanAccess::where('member_id', $row->member_id)->count(), 'vše, nebo nic');
    }

    public function test_bez_prava_403(): void
    {
        $row = $this->memberWithDhcpIp();
        $this->grantAcl(false);
        $la = MemberLanAccess::create(['member_id' => $row->member_id, 'destination' => '10.133.230.0/24']);

        $this->actingAs(User::find(1))
            ->post(route('member_lan_access.store', $row->member_id), ['destinations' => '10.133.139.65'])
            ->assertForbidden();
        $this->actingAs(User::find(1))
            ->delete(route('member_lan_access.destroy', $la->id))
            ->assertForbidden();

        $this->assertSame(1, MemberLanAccess::where('member_id', $row->member_id)->count());
    }

    public function test_detail_clena_zobrazi_sekci_s_vyjimkami(): void
    {
        $row = $this->memberWithDhcpIp();
        $this->grantAcl(true);
        MemberLanAccess::create(['member_id' => $row->member_id, 'destination' => '10.133.230.0/24', 'comment' => 'servery']);

        $this->actingAs(User::find(1))->get(route('members.show', $row->member_id))
            ->assertOk()
            ->assertSee('Přístup do vnitřní sítě')
            ->assertSee('10.133.230.0/24')
            ->assertSee(route('member_lan_access.store', $row->member_id), false);
    }

    public function test_nastaveni_ulozi_izolaci_a_odmitne_nesmysl(): void
    {
        $this->grantAcl(true);
        $user = User::find(1);

        $this->actingAs($user)->put(route('settings.update-network'), [
            'lan_isolation_devices'       => "200, 201",
            'lan_isolation_network'       => '10.133.0.0/16',
            'lan_isolation_public'        => "10.133.37.37\n10.133.230.0/24",
            'lan_isolation_infra_members' => '2748',
        ])->assertSessionHasNoErrors();
        $this->assertSame("200\n201", Setting::get('lan_isolation_devices'));
        $this->assertSame("10.133.37.37\n10.133.230.0/24", Setting::get('lan_isolation_public'));
        $this->assertTrue($this->lan->isEnabledForDevice(201));
        $this->assertTrue($this->lan->isInfraMember(2748));

        $this->actingAs($user)->put(route('settings.update-network'), [
            'lan_isolation_devices' => 'abc',
            'lan_isolation_public'  => '10.133.37.5/24',
        ])->assertSessionHasErrors(['lan_isolation_devices', 'lan_isolation_public']);
        $this->assertSame("200\n201", Setting::get('lan_isolation_devices'), 'při chybě se nic neuloží');
    }

    public function test_odebrani_vyjimky(): void
    {
        $row = $this->memberWithDhcpIp();
        $this->grantAcl(true);
        $la = MemberLanAccess::create(['member_id' => $row->member_id, 'destination' => '10.133.230.0/24']);
        DB::table('subnets')->where('id', $row->subnet_id)->update(['dhcp_expired' => 0]);

        $this->actingAs(User::find(1))
            ->delete(route('member_lan_access.destroy', $la->id))
            ->assertRedirect(route('members.show', $row->member_id));

        $this->assertNull(MemberLanAccess::find($la->id));
        $this->assertSame(1, (int) DB::table('subnets')->where('id', $row->subnet_id)->value('dhcp_expired'));
    }
}
