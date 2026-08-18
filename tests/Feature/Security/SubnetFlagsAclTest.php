<?php

namespace Tests\Feature\Security;

use App\Models\Subnet;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Regrese (audit 2026-08-18, 2. vlna): subnet flagy dhcp/dns/qos jsou ve view
 * podmíněné jemnými právy (canSetDhcp/Dns/Qos = new_all/edit_all na
 * Subnets_Controller#dhcp|dns|qos), ale validateSubnet() je dřív bezpodmínečně
 * pustil do $data a store()/update() je uložily jen pod hrubým právem #subnet.
 * Uživatel s právem na subnety, ale bez dhcp/dns/qos, tak mohl POSTem zapnout
 * DHCP/DNS/QoS server na subnetu.
 *
 * Oprava: validateSubnet() odstraní dhcp/dns/qos z $data, pokud uživatel nemá
 * odpovídající jemné právo (new_all při store, edit_all při update).
 */
class SubnetFlagsAclTest extends DatabaseTestCase
{
    private int $userId = 1;

    private function grantAcl(bool $allowFlags): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(
            function ($userId, $aco, $section, $value) use ($allowFlags) {
                if ($section === 'Subnets_Controller' && in_array($value, ['dhcp', 'dns', 'qos'], true)) {
                    return $allowFlags;
                }
                return true;
            }
        );
    }

    public function test_store_bez_prava_zahodi_dhcp_dns_qos(): void
    {
        $this->grantAcl(false); // smí zakládat subnety, NEsmí dhcp/dns/qos

        $net = '10.253.251.0';
        $this->actingAs(User::find($this->userId))->post(route('subnets.store'), [
            'name'            => 'acltest-store',
            'network_address' => $net,
            'netmask'         => '255.255.255.0',
            'dhcp'            => 1,
            'dns'             => 1,
            'qos'             => 1,
        ]);

        $s = Subnet::where('network_address', $net)->where('netmask', '255.255.255.0')->first();
        $this->assertNotNull($s, 'subnet měl vzniknout');
        $this->assertFalse((bool) $s->dhcp, 'dhcp se nesmí zapnout bez práva');
        $this->assertFalse((bool) $s->dns, 'dns se nesmí zapnout bez práva');
        $this->assertFalse((bool) $s->qos, 'qos se nesmí zapnout bez práva');
    }

    public function test_store_s_pravem_flagy_nastavi(): void
    {
        $this->grantAcl(true);

        $net = '10.253.250.0';
        $this->actingAs(User::find($this->userId))->post(route('subnets.store'), [
            'name'            => 'acltest-store-ok',
            'network_address' => $net,
            'netmask'         => '255.255.255.0',
            'dhcp'            => 1,
            'dns'             => 1,
            'qos'             => 1,
        ]);

        $s = Subnet::where('network_address', $net)->where('netmask', '255.255.255.0')->first();
        $this->assertNotNull($s);
        $this->assertTrue((bool) $s->dhcp);
        $this->assertTrue((bool) $s->dns);
        $this->assertTrue((bool) $s->qos);
    }

    public function test_update_bez_prava_neprepise_dhcp(): void
    {
        // Existující subnet s dhcp=1.
        $net = '10.253.252.0';
        $id = DB::table('subnets')->insertGetId([
            'name' => 'acltest-upd', 'network_address' => $net,
            'netmask' => '255.255.255.0', 'dhcp' => 1, 'dns' => 0, 'qos' => 0,
        ]);

        $this->grantAcl(false); // bez práva na dhcp

        // Útočník se pokusí dhcp vypnout.
        $this->actingAs(User::find($this->userId))->put(route('subnets.update', $id), [
            'name'            => 'acltest-upd',
            'network_address' => $net,
            'netmask'         => '255.255.255.0',
            'dhcp'            => 0,
        ]);

        $s = Subnet::find($id);
        $this->assertTrue((bool) $s->dhcp, 'dhcp musí zůstat beze změny (1) bez práva');
    }
}
