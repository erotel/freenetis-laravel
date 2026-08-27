<?php

namespace Tests\Feature\Devices;

use App\Http\Controllers\DeviceController;
use App\Models\Setting;
use Closure;
use Tests\DatabaseTestCase;

/**
 * Export MikroTik konfigurace (`renderMikrotikFull`):
 *  - pool se NEmaže přes `remove [find]`, ale upsertuje podle jména (`set`),
 *    aby PPP profil vázaný na pool interním ID přežil regeneraci;
 *  - se zapnutým `pppoe_enabled` se add-if-missing generuje PPP profil
 *    (name/remote-address = subnet, local-address = brána) i pppoe-server;
 *  - vypnutý modul / relay režim → žádný PPPoE výstup.
 *
 * Renderu podstrčíme syntetické `$servers` (metoda bere pole) — bez závislosti
 * na konkrétním zařízení v DB. Setting čte z DB, kterou test obalí transakcí.
 */
class PppoeProfileExportTest extends DatabaseTestCase
{
    private Closure $render;

    protected function setUp(): void
    {
        parent::setUp();
        $ctrl = (new \ReflectionClass(DeviceController::class))->newInstanceWithoutConstructor();
        $this->render = Closure::bind(
            fn (array $servers, ?string $relay, string $role) => $this->renderMikrotikFull($servers, $relay, $role),
            $ctrl,
            DeviceController::class
        );
    }

    private function servers(): array
    {
        return [[
            'name'        => 'detkovice',
            'cidr'        => '10.133.71.128/25',
            'network'     => '10.133.71.128',
            'netmask'     => '255.255.255.128',
            'gateway'     => '10.133.71.129',
            'interface'   => 'ether4',
            'range_start' => '10.133.71.130',
            'range_end'   => '10.133.71.254',
            'ranges'      => ['10.133.71.130-10.133.71.254'],
            'dns_servers' => ['10.133.37.37', '10.133.37.38'],
            'hosts'       => [],
        ]];
    }

    public function test_pool_se_upsertuje_ne_maze(): void
    {
        Setting::set('pppoe_enabled', 0);
        $out = ($this->render)($this->servers(), null, 'primary');

        // Žádné destruktivní smazání všech poolů (rozbilo by PPP binding na pool).
        $this->assertStringNotContainsString("/ip pool\r\nremove [find]", $out);
        // Upsert podle jména: existující přepíšeme v místě (ID zůstane).
        $this->assertStringContainsString('/ip pool set [/ip pool find name="detkovice"]', $out);
        $this->assertStringContainsString('/ip pool add name="detkovice" ranges=10.133.71.130-10.133.71.254', $out);
    }

    public function test_pppoe_zapnuto_generuje_profil_i_server(): void
    {
        Setting::set('pppoe_enabled', 1);
        $out = ($this->render)($this->servers(), null, 'primary');

        // PPP profil add-if-missing s očekávaným mapováním.
        $this->assertStringContainsString('[:len [/ppp profile find name="detkovice"]]=0', $out);
        $this->assertStringContainsString(
            '/ppp profile add name="detkovice" local-address=10.133.71.129 remote-address="detkovice"',
            $out
        );
        $this->assertStringContainsString('dns-server=10.133.37.37,10.133.37.38', $out);
        $this->assertStringContainsString('use-ipv6=yes change-tcp-mss=yes', $out);

        // pppoe-server add-if-missing na rozhraní DHCP segmentu.
        $this->assertStringContainsString('[:len [/interface pppoe-server server find interface="ether4"]]=0', $out);
        $this->assertStringContainsString('default-profile="detkovice"', $out);
        $this->assertStringContainsString('service-name="service1"', $out);

        // Žádné mazání profilů/serverů (nesmíme přepsat ruční konfiguraci).
        $this->assertStringNotContainsString('/ppp profile remove', $out);
        $this->assertStringNotContainsString('pppoe-server server remove', $out);
    }

    public function test_pppoe_vypnuto_nic_negeneruje(): void
    {
        Setting::set('pppoe_enabled', 0);
        $out = ($this->render)($this->servers(), null, 'primary');
        $this->assertStringNotContainsString('/ppp profile', $out);
        $this->assertStringNotContainsString('pppoe-server', $out);
    }

    public function test_relay_rezim_pppoe_negeneruje(): void
    {
        Setting::set('pppoe_enabled', 1);
        // Relay MK není zákaznická brána → žádný lokální pppoe-server ani profil.
        $out = ($this->render)($this->servers(), 'vlan1010', 'primary');
        $this->assertStringNotContainsString('/ppp profile', $out);
        $this->assertStringNotContainsString('pppoe-server', $out);
    }
}
