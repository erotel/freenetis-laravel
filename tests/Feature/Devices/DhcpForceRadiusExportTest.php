<?php

namespace Tests\Feature\Devices;

use App\Http\Controllers\DeviceController;
use App\Models\Setting;
use Closure;
use Tests\DatabaseTestCase;

/**
 * Trvalé vynucení use-radius / tr101 v exportu MikroTik DHCP configu
 * (dhcp_force_radius / dhcp_force_tr101). Optické brány (např. 10.133.0.16)
 * jedou přes RADIUS + line-id ještě než se subnet přepne na ipoe; bez tohoto
 * by remove[find]+re-add při načtení configu ruční nastavení smazal.
 * Viz [[project_lineid_tr101_gotcha]].
 */
class DhcpForceRadiusExportTest extends DatabaseTestCase
{
    private Closure $render;
    private Closure $inList;

    protected function setUp(): void
    {
        parent::setUp();
        $ctrl = (new \ReflectionClass(DeviceController::class))->newInstanceWithoutConstructor();
        $this->render = Closure::bind(
            fn (array $servers, ?string $relay, string $role, bool $fr, bool $ft)
                => $this->renderMikrotikFull($servers, $relay, $role, $fr, $ft),
            $ctrl,
            DeviceController::class
        );
        $this->inList = Closure::bind(
            fn (int $id, string $key) => $this->deviceInDhcpList($id, $key),
            $ctrl,
            DeviceController::class
        );
    }

    private function servers(bool $ipoe = false): array
    {
        return [[
            'name'        => 'urcice_skolni',
            'cidr'        => '10.133.173.0/24',
            'network'     => '10.133.173.0',
            'netmask'     => '255.255.255.0',
            'gateway'     => '10.133.173.1',
            'interface'   => 'bridge6',
            'range_start' => '10.133.173.10',
            'range_end'   => '10.133.173.254',
            'ranges'      => ['10.133.173.10-10.133.173.254'],
            'dns_servers' => ['10.133.37.37', '10.133.37.38'],
            'hosts'       => [],
            'ipoe'        => $ipoe,
        ]];
    }

    public function test_force_radius_i_tr101_se_zapisou_i_bez_ipoe(): void
    {
        $out = ($this->render)($this->servers(false), null, 'primary', true, true);
        $this->assertStringContainsString('use-radius=yes', $out);
        $this->assertStringContainsString('support-broadband-tr101=yes', $out);
    }

    public function test_bez_force_a_bez_ipoe_nic_navic(): void
    {
        $out = ($this->render)($this->servers(false), null, 'primary', false, false);
        $this->assertStringNotContainsString('use-radius=yes', $out);
        $this->assertStringNotContainsString('support-broadband-tr101=yes', $out);
    }

    public function test_ipoe_da_radius_ale_ne_tr101_bez_force(): void
    {
        // ipoe subnet → use-radius (Framed-IP), ale tr101 jen přes force list.
        $out = ($this->render)($this->servers(true), null, 'primary', false, false);
        $this->assertStringContainsString('use-radius=yes', $out);
        $this->assertStringNotContainsString('support-broadband-tr101=yes', $out);
    }

    public function test_force_radius_v_relay_rezimu(): void
    {
        // 230.2/230.3 jedou relay → use-radius na relay add řádku, tr101 tam netřeba.
        $out = ($this->render)($this->servers(false), 'vlan1010', 'primary', true, false);
        $this->assertStringContainsString('relay=10.133.173.1', $out);
        $this->assertStringContainsString('use-radius=yes', $out);
        $this->assertStringNotContainsString('support-broadband-tr101=yes', $out);
    }

    public function test_device_in_dhcp_list_newline_i_carka(): void
    {
        Setting::set('dhcp_force_radius', "207\n9672\n200");
        Setting::set('dhcp_force_tr101', '200');
        $this->assertTrue(($this->inList)(207, 'dhcp_force_radius'));
        $this->assertTrue(($this->inList)(9672, 'dhcp_force_radius'));
        $this->assertTrue(($this->inList)(200, 'dhcp_force_tr101'));
        $this->assertFalse(($this->inList)(9672, 'dhcp_force_tr101'));
        $this->assertFalse(($this->inList)(999, 'dhcp_force_radius'));

        // čárkou oddělené taky projde
        Setting::set('dhcp_force_radius', '207, 9672');
        $this->assertTrue(($this->inList)(9672, 'dhcp_force_radius'));
        // prázdné nastavení → false
        Setting::set('dhcp_force_tr101', '');
        $this->assertFalse(($this->inList)(200, 'dhcp_force_tr101'));
    }
}
