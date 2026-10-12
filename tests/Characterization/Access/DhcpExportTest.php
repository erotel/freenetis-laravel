<?php

namespace Tests\Characterization\Access;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace GET devices/{id}/export/{format} — DHCP konfigurace pro MikroTik
 * (DeviceController::export + buildDhcpServers + render*). Stahují ji DHCP
 * servery (MK) každých 5 min přes dhcp_api_token.
 *
 * Zachycuje SOUČASNÉ chování před přesunem do App\Domains\Access — celý text
 * exportu doslovně (golden), detekci změn (legacy dhcp_expired i per-client
 * high-water-mark) a vedlejší zápisy. Při refaktoru se aserce nemění.
 *
 * Scénář (vlastní syntetická data v nepoužívaném rozsahu 10.250.x):
 *  - DHCP zařízení, rozhraní „ether9":
 *      A 10.250.1.0/28  „Testovací Č"  dhcp, dns   brána .1
 *      B 10.250.2.0/29  „IPoE B"       dhcp, ipoe  brána .1
 *      C 10.250.3.0/29  „Bez DHCP"     dhcp=0      brána .1   → vynechán
 *      D 10.250.4.0/29  „Ne brána"     dhcp        .1 bez gateway → vynechán
 *  - hosté v A: .5 (MAC …:02), .9 (MAC …:01), .11 (neplatná MAC → bez leasu,
 *    ale z poolu vyříznutý), .13 bez rozhraní (pool ho nevyřízne);
 *    čekající žádost o připojení s IP .12 (pppoe_enabled → vyříznutá)
 *  - host v B: .4 (ipoe → bez leasu)
 */
class DhcpExportTest extends DatabaseTestCase
{
    private const TOKEN = 'char-dhcp-token-xyz';

    private int $deviceId;
    private int $memberId;
    private array $subnet = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00.250000'));

        $apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $type = (int) DB::table('devices')->value('type');
        if (!$apId || !$type) {
            $this->markTestSkipped('chybí adresní bod / typ zařízení');
        }

        foreach ([
            'dhcp_api_token'        => self::TOKEN,
            'dns_servers'           => '10.133.37.37,10.133.37.38',
            'dhcp_lease_time'       => '3600',
            'pppoe_enabled'         => '1',
            'pppoe_service_name'    => 'svc-č',
            'dhcp_relay_map'        => '',
            'dhcp_force_radius'     => '',
            'dhcp_force_tr101'      => '',
            'lan_isolation_devices' => '',
        ] as $k => $v) {
            Setting::set($k, $v);
        }

        $this->memberId = (int) DB::table('members')->insertGetId([
            'name' => 'Char DHCP', 'type' => 2, 'leaving_date' => '9999-12-31',
            'registration' => 1, 'locked' => 0, 'address_point_id' => $apId,
        ]);
        $userId = (int) DB::table('users')->insertGetId([
            'member_id' => $this->memberId, 'login' => 'char_dhcp_' . uniqid(),
            'password' => Hash::make('x'), 'type' => 1, 'application_password' => 'xxxxxxxx',
            'settings' => '', 'name' => 'Char', 'surname' => 'Dhcp', 'comment' => '',
        ]);

        $this->deviceId = (int) DB::table('devices')->insertGetId(['name' => 'char-router', 'type' => $type, 'user_id' => $userId]);
        $gwIface = $this->iface($this->deviceId, 'ether9', null);

        $this->subnet['A'] = $this->subnetRow('Testovací Č', '10.250.1.0', '255.255.255.240', dhcp: 1, dns: 1);
        $this->subnet['B'] = $this->subnetRow('IPoE B', '10.250.2.0', '255.255.255.248', dhcp: 1, ipoe: 1);
        $this->subnet['C'] = $this->subnetRow('Bez DHCP', '10.250.3.0', '255.255.255.248', dhcp: 0);
        $this->subnet['D'] = $this->subnetRow('Ne brana', '10.250.4.0', '255.255.255.248', dhcp: 1);

        $this->ip($gwIface, 'A', '10.250.1.1', gateway: 1);
        $this->ip($gwIface, 'B', '10.250.2.1', gateway: 1);
        $this->ip($gwIface, 'C', '10.250.3.1', gateway: 1);
        $this->ip($gwIface, 'D', '10.250.4.1', gateway: 0);

        $cpe = (int) DB::table('devices')->insertGetId(['name' => 'char-cpe', 'type' => $type, 'user_id' => $userId]);
        $this->ip($this->iface($cpe, 'wlan1', 'AA:BB:CC:00:00:02'), 'A', '10.250.1.5');
        $this->ip($this->iface($cpe, 'wlan2', 'AA:BB:CC:00:00:01'), 'A', '10.250.1.9');
        $this->ip($this->iface($cpe, 'wlan3', 'neplatna'), 'A', '10.250.1.11');
        $this->ip(null, 'A', '10.250.1.13');
        $this->ip($this->iface($cpe, 'wlan4', 'AA:BB:CC:00:00:04'), 'B', '10.250.2.4');

        DB::table('connection_requests')->insert([
            'member_id' => $this->memberId, 'state' => 0, 'created_at' => now(), 'ip_address' => '10.250.1.12',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function iface(int $deviceId, string $name, ?string $mac): int
    {
        return (int) DB::table('ifaces')->insertGetId(['device_id' => $deviceId, 'type' => 1, 'name' => $name, 'mac' => $mac]);
    }

    private function subnetRow(string $name, string $net, string $mask, int $dhcp, int $dns = 0, int $ipoe = 0): int
    {
        return (int) DB::table('subnets')->insertGetId([
            'name' => $name, 'network_address' => $net, 'netmask' => $mask,
            'dhcp' => $dhcp, 'dns' => $dns, 'ipoe' => $ipoe, 'dhcp_expired' => 0, 'dhcp_changed_at' => null,
        ]);
    }

    private function ip(?int $ifaceId, string $subnet, string $ip, int $gateway = 0): void
    {
        DB::table('ip_addresses')->insert([
            'iface_id' => $ifaceId, 'subnet_id' => $this->subnet[$subnet], 'ip_address' => $ip, 'gateway' => $gateway,
        ]);
    }

    private function export(string $format = 'mikrotik-ip-dhcp-server', array $query = [], ?int $deviceId = null)
    {
        $url = route('devices.export', ['id' => $deviceId ?? $this->deviceId, 'format' => $format]);
        return $this->get($url . '?' . http_build_query($query + ['token' => self::TOKEN]));
    }

    private function comment(string $device = 'char-cpe'): string
    {
        return "ID {$this->memberId} - Char DHCP - {$device}";
    }

    private const LAN_CLEANUP =
        "/ip firewall filter remove [/ip firewall filter find comment~\"^fn-lan\"]\r\n"
        . "/ip firewall address-list remove [/ip firewall address-list find list~\"^fn-lan\"]\r\n";

    // ── plný export ──────────────────────────────────────────────────────────

    public function test_plny_export_doslovne(): void
    {
        $r = $this->export(query: ['forced' => 1]);
        $r->assertOk();
        $this->assertSame('text/plain; charset=utf-8', $r->headers->get('Content-Type'));

        $expected =
            ":if ([:len [/ip pool find name=\"Testovaci C\"]]=0) do={/ip pool add name=\"Testovaci C\" ranges=10.250.1.2-10.250.1.4,10.250.1.6-10.250.1.8,10.250.1.10-10.250.1.10,10.250.1.13-10.250.1.14} else={/ip pool set [/ip pool find name=\"Testovaci C\"] ranges=10.250.1.2-10.250.1.4,10.250.1.6-10.250.1.8,10.250.1.10-10.250.1.10,10.250.1.13-10.250.1.14}\r\n"
            . ":if ([:len [/ip pool find name=\"IPoE B\"]]=0) do={/ip pool add name=\"IPoE B\" ranges=10.250.2.2-10.250.2.3,10.250.2.5-10.250.2.6} else={/ip pool set [/ip pool find name=\"IPoE B\"] ranges=10.250.2.2-10.250.2.3,10.250.2.5-10.250.2.6}\r\n"
            . "/ip dhcp-server\r\nremove [find]\r\n"
            . "add name=\"Testovaci C\" address-pool=\"Testovaci C\" authoritative=after-2sec-delay bootp-support=static disabled=no interface=\"ether9\" lease-time=01:00:00\r\n"
            . "add name=\"IPoE B\" address-pool=\"IPoE B\" authoritative=after-2sec-delay bootp-support=static disabled=no interface=\"ether9\" lease-time=01:00:00 use-radius=yes\r\n"
            . "/ip dhcp-server network\r\nremove [find]\r\n"
            . "add address=10.250.1.0/28 dhcp-option=\"\" dns-server=10.250.1.1,10.133.37.37,10.133.37.38 gateway=10.250.1.1 ntp-server=\"\" wins-server=\"\"\r\n"
            . "add address=10.250.2.0/29 dhcp-option=\"\" dns-server=10.133.37.37,10.133.37.38 gateway=10.250.2.1 ntp-server=\"\" wins-server=\"\"\r\n"
            . "/ip dhcp-server lease\r\nremove [find]\r\n"
            . "add address=10.250.1.9 disabled=no mac-address=AA:BB:CC:00:00:01 server=\"Testovaci C\" comment=\"{$this->comment()}\"\r\n"
            . "add address=10.250.1.5 disabled=no mac-address=AA:BB:CC:00:00:02 server=\"Testovaci C\" comment=\"{$this->comment()}\"\r\n"
            . ":if ([:len [/ppp profile find name=\"Testovaci C\"]]=0) do={/ppp profile add name=\"Testovaci C\" local-address=10.250.1.1 remote-address=\"Testovaci C\" dns-server=10.250.1.1,10.133.37.37,10.133.37.38 use-ipv6=yes change-tcp-mss=yes}\r\n"
            . ":if ([:len [/interface pppoe-server server find interface=\"ether9\"]]=0) do={/interface pppoe-server server add service-name=\"svc-c\" interface=\"ether9\" default-profile=\"Testovaci C\" disabled=no one-session-per-host=yes authentication=pap,chap,mschap2}\r\n"
            . ":if ([:len [/ppp profile find name=\"IPoE B\"]]=0) do={/ppp profile add name=\"IPoE B\" local-address=10.250.2.1 remote-address=\"IPoE B\" dns-server=10.133.37.37,10.133.37.38 use-ipv6=yes change-tcp-mss=yes}\r\n"
            . self::LAN_CLEANUP;

        $this->assertSame($expected, $r->getContent());
    }

    public function test_lease_export_doslovne(): void
    {
        $r = $this->export('mikrotik-ip-dhcp-server-lease', ['forced' => 1]);
        $r->assertOk();

        $this->assertSame(
            "/ip dhcp-server lease\r\n"
            . "remove [find server=\"Testovaci C\"]\r\n"
            . "add address=10.250.1.9 disabled=no mac-address=AA:BB:CC:00:00:01 server=\"Testovaci C\" comment=\"{$this->comment()}\"\r\n"
            . "add address=10.250.1.5 disabled=no mac-address=AA:BB:CC:00:00:02 server=\"Testovaci C\" comment=\"{$this->comment()}\"\r\n"
            . "remove [find server=\"IPoE B\"]\r\n",
            $r->getContent()
        );
    }

    public function test_relay_static_role_force_radius_a_tr101(): void
    {
        Setting::set('dhcp_relay_map', "1=x\n{$this->deviceId} = vlan99\n");
        Setting::set('dhcp_force_radius', "5, {$this->deviceId}");
        Setting::set('dhcp_force_tr101', (string) $this->deviceId);

        $out = $this->export(query: ['forced' => 1, 'role' => 'static'])->assertOk()->getContent();

        $this->assertStringContainsString(
            "/ip dhcp-server\r\nremove [find]\r\n"
            . "add name=\"Testovaci C\" authoritative=after-10sec-delay bootp-support=static disabled=no interface=\"vlan99\" relay=10.250.1.1 lease-time=01:00:00 address-pool=static-only use-radius=yes support-broadband-tr101=yes\r\n"
            . "add name=\"IPoE B\" authoritative=after-10sec-delay bootp-support=static disabled=no interface=\"vlan99\" relay=10.250.2.1 lease-time=01:00:00 address-pool=static-only use-radius=yes support-broadband-tr101=yes\r\n",
            $out
        );
        // relay MK: žádné PPPoE ani izolace LAN
        $this->assertStringNotContainsString('/ppp profile', $out);
        $this->assertStringNotContainsString('fn-lan', $out);
        $this->assertStringEndsWith("comment=\"{$this->comment()}\"\r\n", $out);
    }

    public function test_relay_primary(): void
    {
        Setting::set('dhcp_relay_map', "{$this->deviceId}=vlan99");
        $out = $this->export(query: ['forced' => 1, 'role' => 'cokoliv'])->assertOk()->getContent();

        $this->assertStringContainsString(
            "add name=\"Testovaci C\" address-pool=\"Testovaci C\" authoritative=yes bootp-support=static disabled=no interface=\"vlan99\" relay=10.250.1.1 lease-time=01:00:00\r\n",
            $out
        );
    }

    /** Prázdné / nulové dhcp_lease_time → 3 h. */
    public function test_nulovy_lease_time_fallback(): void
    {
        Setting::set('dhcp_lease_time', '0');
        $this->assertStringContainsString('lease-time=03:00:00', $this->export(query: ['forced' => 1])->getContent());
    }

    /** Literální „\n" v dns_servers a nevalidní položky se odfiltrují. */
    public function test_dns_servers_normalizace(): void
    {
        Setting::set('dns_servers', '10.0.0.1\n10.0.0.2; garbage|10.0.0.3');
        $this->assertStringContainsString(
            'dns-server=10.0.0.1,10.0.0.2,10.0.0.3 gateway=10.250.2.1',
            $this->export(query: ['forced' => 1])->getContent()
        );
    }

    public function test_pppoe_vypnuto_cekajici_zadost_neni_vyriznuta(): void
    {
        Setting::set('pppoe_enabled', '0');
        $out = $this->export(query: ['forced' => 1])->getContent();
        $this->assertStringContainsString('ranges=10.250.1.2-10.250.1.4,10.250.1.6-10.250.1.8,10.250.1.10-10.250.1.10,10.250.1.12-10.250.1.14}', $out);
        $this->assertStringNotContainsString('/ppp profile', $out);
    }

    // ── detekce změn: legacy dhcp_expired ────────────────────────────────────

    public function test_legacy_bez_zmeny_204(): void
    {
        $this->export()->assertNoContent();
    }

    public function test_legacy_zmena_vyexportuje_a_shodi_flag_vsem_dhcp_subnetum(): void
    {
        DB::table('subnets')->whereIn('id', [$this->subnet['A'], $this->subnet['B'], $this->subnet['C']])->update(['dhcp_expired' => 1]);

        $this->export()->assertOk();

        $flags = DB::table('subnets')->whereIn('id', $this->subnet)->pluck('dhcp_expired', 'id');
        $this->assertSame(0, (int) $flags[$this->subnet['A']]);
        $this->assertSame(0, (int) $flags[$this->subnet['B']]);
        $this->assertSame(1, (int) $flags[$this->subnet['C']], 'subnet bez DHCP se nesahá');
        $this->export()->assertNoContent();
    }

    /** Flag na subnetu bez DHCP export nespustí. */
    public function test_legacy_flag_na_subnetu_bez_dhcp_204(): void
    {
        DB::table('subnets')->where('id', $this->subnet['C'])->update(['dhcp_expired' => 1]);
        $this->export()->assertNoContent();
    }

    public function test_forced_s_tokenem_flag_shodi(): void
    {
        DB::table('subnets')->where('id', $this->subnet['A'])->update(['dhcp_expired' => 1]);
        $this->export(query: ['forced' => 1])->assertOk();
        // forced s tokenem flag shodí (zápis „aktuální" běží i při forced)
        $this->assertSame(0, (int) DB::table('subnets')->where('id', $this->subnet['A'])->value('dhcp_expired'));
    }

    // ── detekce změn: per-client ─────────────────────────────────────────────

    public function test_client_prvni_stazeni_zapise_high_water_mark(): void
    {
        $this->export(query: ['client' => 'char-mk.1'])->assertOk();

        $this->assertSame(
            '2026-10-12 12:00:00.250000',
            DB::table('dhcp_export_state')->where('client', 'char-mk.1')->value('exported_at')
        );
        // per-client nesahá na legacy flag
        DB::table('subnets')->where('id', $this->subnet['A'])->update(['dhcp_expired' => 1]);
        $this->export(query: ['client' => 'char-mk.1'])->assertNoContent();
        $this->assertSame(1, (int) DB::table('subnets')->where('id', $this->subnet['A'])->value('dhcp_expired'));
    }

    public function test_client_zmena_po_stazeni_exportuje_znovu(): void
    {
        $this->export(query: ['client' => 'char-mk'])->assertOk();
        $this->export(query: ['client' => 'char-mk'])->assertNoContent();

        // změna starší než stažení → pořád 204
        DB::table('subnets')->where('id', $this->subnet['A'])->update(['dhcp_changed_at' => '2026-10-12 12:00:00.249999']);
        $this->export(query: ['client' => 'char-mk'])->assertNoContent();

        // shoda na mikrosekundu → raději re-export
        DB::table('subnets')->where('id', $this->subnet['B'])->update(['dhcp_changed_at' => '2026-10-12 12:00:00.250000']);
        $this->export(query: ['client' => 'char-mk'])->assertOk();
    }

    /**
     * Změna na subnetu bez DHCP se nepočítá; na DHCP subnetu, kde zařízení
     * NENÍ brána, ano (quirk: detekce nekontroluje gateway, export ho vynechá).
     */
    public function test_client_zmena_subnetu_bez_dhcp_vs_ne_brana(): void
    {
        $this->export(query: ['client' => 'char-mk'])->assertOk();
        DB::table('subnets')->where('id', $this->subnet['C'])->update(['dhcp_changed_at' => '2026-10-12 13:00:00']);
        $this->export(query: ['client' => 'char-mk'])->assertNoContent();
        DB::table('subnets')->where('id', $this->subnet['D'])->update(['dhcp_changed_at' => '2026-10-12 13:00:00']);
        $this->export(query: ['client' => 'char-mk'])->assertOk();
    }

    public function test_client_se_sanitizuje(): void
    {
        $this->export(query: ['client' => ' čar/mk;1 '])->assertOk();
        $this->assertTrue(DB::table('dhcp_export_state')->where('client', 'armk1')->exists());

        $this->export(query: ['client' => str_repeat('x', 80)])->assertOk();
        $this->assertTrue(DB::table('dhcp_export_state')->where('client', str_repeat('x', 64))->exists());
    }

    /** Klient složený jen ze zakázaných znaků = legacy režim. */
    public function test_client_prazdny_po_sanitizaci_je_legacy(): void
    {
        $this->export(query: ['client' => '///'])->assertNoContent();
    }

    // ── vedlejší efekty a přístup ────────────────────────────────────────────

    public function test_token_aktualizuje_access_time(): void
    {
        $this->export()->assertNoContent();
        $this->assertSame('2026-10-12 12:00:00', DB::table('devices')->where('id', $this->deviceId)->value('access_time'));
    }

    public function test_token_v_bearer_hlavicce(): void
    {
        $url = route('devices.export', ['id' => $this->deviceId, 'format' => 'mikrotik-ip-dhcp-server']);
        $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)->get($url . '?forced=1')->assertOk();
    }

    public function test_prihlaseny_s_acl_bez_tokenu_nic_nezapise(): void
    {
        $acl = $this->mock(\App\Services\AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true);
        $admin = \App\Models\User::find((int) DB::table('users')->where('type', 1)->value('id'));
        DB::table('subnets')->where('id', $this->subnet['A'])->update(['dhcp_expired' => 1]);

        $url = route('devices.export', ['id' => $this->deviceId, 'format' => 'mikrotik-ip-dhcp-server']);
        // bez tokenu se detekce změn přeskakuje → vždy 200, ale nic se neoznačí jako stažené
        $this->actingAs($admin)->get($url . '?client=char-ui')->assertOk();
        $this->assertSame(1, (int) DB::table('subnets')->where('id', $this->subnet['A'])->value('dhcp_expired'));
        $this->assertFalse(DB::table('dhcp_export_state')->where('client', 'char-ui')->exists());
        $this->assertNull(DB::table('devices')->where('id', $this->deviceId)->value('access_time'));
    }

    public function test_neznamy_format_404(): void
    {
        $this->export('csv')->assertNotFound();
    }

    /** Neexistující zařízení 404 i bez autorizace (kontrola existence je před auth). */
    public function test_neexistujici_zarizeni_404_i_bez_tokenu(): void
    {
        $url = route('devices.export', ['id' => PHP_INT_MAX >> 33, 'format' => 'mikrotik-ip-dhcp-server']);
        $this->get($url)->assertNotFound();
    }

    public function test_bez_tokenu_a_neprihlaseny_403(): void
    {
        $url = route('devices.export', ['id' => $this->deviceId, 'format' => 'mikrotik-ip-dhcp-server']);
        $this->get($url . '?token=spatny')->assertForbidden();
    }

    public function test_legacy_kohana_url(): void
    {
        $this->get("/index.php/en/devices/export/{$this->deviceId}/mikrotik-ip-dhcp-server-lease/text?forced=1&token=" . self::TOKEN)
            ->assertOk()
            ->assertSee('remove [find server="IPoE B"]', false);
    }
}
