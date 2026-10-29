<?php

namespace Tests\Feature\Devices;

use App\Domains\Access\Dhcp\MikrotikDhcpRenderer;
use App\Models\Setting;
use Tests\DatabaseTestCase;

/**
 * Řetězce v RouterOS DHCP exportu (jméno člena / zařízení v komentáři leasu,
 * název subnetu, rozhraní, PPPoE služby) se escapují — MK skript importuje přes
 * `/import`, neescapovaná uvozovka nebo nový řádek ze jména z veřejné
 * registrace by jinak vložil vlastní příkaz do routeru.
 */
class DhcpExportEscapingTest extends DatabaseTestCase
{
    private const EVIL_COMMENT = "ID 1 - x\" disabled=yes [/user add name=a group=full] comment=\"\r\n/system reset-configuration - \$dev {y}";

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('dhcp_lease_time', '3600');
        Setting::set('pppoe_enabled', '1');
        Setting::set('pppoe_service_name', 'svc"x');
    }

    private function servers(string $comment, string $name = 'Sit "a" $b', string $iface = "vlan 137\"\n"): array
    {
        return [[
            'subnet_id' => 1, 'name' => $name, 'cidr' => '10.250.1.0/28', 'gateway' => '10.250.1.1',
            'interface' => $iface, 'range_start' => '10.250.1.2', 'range_end' => '10.250.1.14', 'ranges' => [],
            'dns_servers' => ['10.250.1.1'], 'ipoe' => 0,
            'hosts' => [['ip_address' => '10.250.1.5', 'mac' => 'AA:BB:CC:00:00:01', 'server' => $name, 'comment' => $comment]],
        ]];
    }

    public function test_zly_komentar_nevlozi_prikaz(): void
    {
        $out = (new MikrotikDhcpRenderer())->renderLeaseOnly($this->servers(self::EVIL_COMMENT));

        $this->assertSame(
            "/ip dhcp-server lease\r\n"
            . "remove [find server=\"Sit \\\"a\\\" \\\$b\"]\r\n"
            . "add address=10.250.1.5 disabled=no mac-address=AA:BB:CC:00:00:01 server=\"Sit \\\"a\\\" \\\$b\" "
            . "comment=\"ID 1 - x\\\" disabled=yes (/user add name=a group=full) comment=\\\"  /system reset-configuration - \\\$dev (y)\"\r\n",
            $out
        );
    }

    public function test_plny_export_escapuje_nazvy_a_rozhrani(): void
    {
        $out = (new MikrotikDhcpRenderer())->renderFull($this->servers(self::EVIL_COMMENT));

        $this->assertSame(12, substr_count($out, "\r\n"), 'žádný řádek navíc (12 příkazů)');
        $this->assertStringContainsString('add name="Sit \"a\" \$b" address-pool="Sit \"a\" \$b"', $out);
        $this->assertStringContainsString('interface="vlan 137\" "', $out);
        $this->assertStringContainsString('service-name="svc\"x"', $out);
        $this->assertStringNotContainsString("\n/system", $out);

        $relay = (new MikrotikDhcpRenderer())->renderFull($this->servers('ok'), "vlan1010\" disabled=yes");
        $this->assertStringContainsString('interface="vlan1010\" disabled=yes"', $relay);
    }

    public function test_bezne_znaky_zustanou_citelne(): void
    {
        $out = (new MikrotikDhcpRenderer())->renderLeaseOnly(
            $this->servers('ID 5 - Charvát & syn – J|M (c) - HačkySever_RB1???', 'NET_14.128_26_Cechovice_AP2+LAN')
        );

        $this->assertStringContainsString(
            'server="NET_14.128_26_Cechovice_AP2+LAN" comment="ID 5 - Charvát & syn – J|M (c) - HačkySever_RB1\?\?\?"',
            $out
        );
    }
}
