<?php

namespace Tests\Characterization\Access;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace M2M exportů pro brány (WebInterfaceController, /web-interface/*
 * + legacy aliasy) před přesunem do domény Access. Autentizace (token / IP /
 * vynucení): Feature/Security/WebInterfaceTokenTest. Tady formát výstupů:
 * přesměrování (rozsahy, povolené / nepovolené / self-cancel IP, IPv6), IPv6
 * pro RADIUS, QoS JSON (profily, rychlost místa > člena, odvozená IPv6),
 * port forwardy a NAT 1:1 (JSON / txt, scope), SMTP výjimky.
 */
class GatewayExportTest extends DatabaseTestCase
{
    private const TOKEN = '__selftest_gw_token_0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('web_interface_api_token', self::TOKEN);
        Setting::set('redirection_enabled', '1');
        Setting::set('ipv6_prefix', '2a07:9c0');
        Setting::set('ipv6_mask', '56');
    }

    private function gw(string $path, array $q = [])
    {
        return $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)->get('/web-interface/' . $path . ($q ? '?' . http_build_query($q) : ''));
    }

    private function lines($res): array
    {
        $this->assertSame('text/plain; charset=utf-8', $res->headers->get('Content-Type'));
        $body = $res->getContent();
        $this->assertStringEndsWith("\n", $body);
        return explode("\n", rtrim($body, "\n"));
    }

    /** @return array{member:int, ips:array<string,int>} */
    private function member(array $ips, ?int $speed = null): array
    {
        $apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $id = (int) DB::table('members')->insertGetId(['name' => 'Char GW', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $apId, 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31', 'speed_class_id' => $speed]);
        $uid = (int) DB::table('users')->insertGetId(['member_id' => $id, 'login' => 'chargw_' . uniqid(), 'password' => 'x', 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'G', 'comment' => '']);
        $iface = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'e', 'mac' => sprintf('02:6A:00:00:%02X:%02X', $id % 256, random_int(0, 255)),
            'device_id' => DB::table('devices')->insertGetId(['name' => 'c', 'type' => 1, 'user_id' => $uid])]);
        $out = [];
        foreach ($ips as $ip => $subnet) {
            $out[$ip] = (int) DB::table('ip_addresses')->insertGetId(['iface_id' => $iface, 'subnet_id' => $subnet, 'ip_address' => $ip]);
        }
        return ['member' => $id, 'ips' => $out, 'iface' => $iface];
    }

    private function subnet(string $net, string $mask, int $redirect = 0): int
    {
        return (int) DB::table('subnets')->insertGetId(['name' => 'char gw', 'network_address' => $net, 'netmask' => $mask, 'redirect' => $redirect]);
    }

    private function speed(string $name, int $down, int $up): int
    {
        return (int) DB::table('speed_classes')->insertGetId(['name' => $name, 'd_ceil' => $down * 2, 'd_rate' => $down, 'u_ceil' => $up * 2, 'u_rate' => $up]);
    }

    // ── přesměrování ─────────────────────────────────────────────────────────

    public function test_presmerovani_seznamy(): void
    {
        $s = $this->subnet('10.251.10.0', '255.255.255.128', 1);
        $x = $this->member(['10.251.10.5' => $s, '10.251.10.6' => $s, '10.251.10.7' => $s]);
        $selfMsg = (int) DB::table('messages')->insertGetId(['name' => 'c', 'text' => 't', 'type' => 0, 'self_cancel' => 1, 'ignore_whitelist' => 0]);
        $hardMsg = (int) DB::table('messages')->insertGetId(['name' => 'c2', 'text' => 't', 'type' => 99, 'self_cancel' => 0, 'ignore_whitelist' => 0]);
        DB::table('messages_ip_addresses')->insert([
            ['message_id' => $selfMsg, 'ip_address_id' => $x['ips']['10.251.10.6'], 'datetime' => now()],
            ['message_id' => $selfMsg, 'ip_address_id' => $x['ips']['10.251.10.7'], 'datetime' => now()],
            ['message_id' => $hardMsg, 'ip_address_id' => $x['ips']['10.251.10.7'], 'datetime' => now()],
        ]);

        $this->assertContains('10.251.10.0/25', $this->lines($this->gw('redirected-ranges')));
        $allowed = $this->lines($this->gw('allowed-ip-addresses'));
        $this->assertContains('10.251.10.5', $allowed);
        $this->assertNotContains('10.251.10.6', $allowed);
        $un = $this->lines($this->gw('unallowed-ip-addresses'));
        $this->assertSame([false, true, true], [in_array('10.251.10.5', $un), in_array('10.251.10.6', $un), in_array('10.251.10.7', $un)]);
        $this->assertSame(['10.251.10.7'], array_values(array_intersect(['10.251.10.5', '10.251.10.6', '10.251.10.7'], $this->lines($this->gw('unallowed-ip-addresses/99')))));
        $self = $this->lines($this->gw('self-cancelable-ip-addresses'));
        $this->assertSame([true, false], [in_array('10.251.10.6', $self), in_array('10.251.10.7', $self)], 'self-cancel jen když VŠECHNY zprávy');

        $v6 = $this->lines($this->gw('allowed-ip6-addresses'));
        $this->assertContains('2a07:9c0:a:500::/56', $v6);
        $this->assertNotContains('2a07:9c0:a:600::/56', $v6);
        Setting::set('ipv6_prefix', '');
        Setting::set('ipv6_mask', '');
        $this->assertContains('2a07:9c0:a:500::/56', $this->lines($this->gw('allowed-ip6-addresses')), 'prázdné nastavení → výchozí');

        $this->assertNotEmpty(Setting::get('redirection_state'));
        foreach (['/web_interface/allowed_ip_addresses', '/en/web_interface/allowed_ip_addresses'] as $legacy) {
            $this->assertContains('10.251.10.5', $this->lines($this->withHeader('Authorization', 'Bearer ' . self::TOKEN)->get($legacy)), "legacy alias {$legacy}");
        }

        Setting::set('redirection_enabled', '0');
        $this->gw('allowed-ip-addresses')->assertForbidden();
        $this->gw('qos-json')->assertOk();
    }

    public function test_ipv6_pro_radius(): void
    {
        $x = $this->member([]);
        DB::table('ip6_addresses')->insert(['iface_id' => $x['iface'], 'ip_address' => '2a07:9c0:ff:ff00::/56']);
        $mac = DB::table('ifaces')->where('id', $x['iface'])->value('mac');
        $this->assertContains('2a07:9c0:ff:ff00::/56,' . $mac, $this->lines($this->gw('ipv6-radius')));
    }

    // ── QoS ──────────────────────────────────────────────────────────────────

    public function test_qos_profily_clen_misto_a_ipv6(): void
    {
        $fast = $this->speed('Char Fast', 100000000, 20000000);
        $slow = $this->speed('Char Slow', 10000000, 2000000);
        $sA = $this->subnet('10.251.11.0', '255.255.255.0');
        $sB = $this->subnet('10.251.12.0', '255.255.255.0');
        $x = $this->member(['10.251.11.5' => $sA, '10.251.11.6' => $sA, '10.251.12.9' => $sB, '89.0.0.1' => $sB], $fast);
        DB::table('allowed_subnets')->insert(['member_id' => $x['member'], 'subnet_id' => $sB, 'enabled' => 1, 'speed_class_id' => $slow]);
        $noSpeed = $this->member(['10.251.11.7' => $sA]);

        $json = $this->gw('qos-json')->assertOk()->json();
        $this->assertMatchesRegularExpression('~^\d{4}-\d{2}-\d{2}T~', $json['generated_at']);
        $p = collect($json['profiles'])->firstWhere('id', $fast);
        $this->assertSame(['id' => $fast, 'name' => 'Char Fast', 'up_kbit' => 20480, 'down_kbit' => 102400, 'up_ceil_kbit' => 40960, 'down_ceil_kbit' => 204800], $p);

        $m = collect($json['members'])->firstWhere('member_id', $x['member']);
        $this->assertSame([
            'member_id'   => $x['member'],
            'profile_id'  => $fast,
            'ipv4'        => ['10.251.11.5', '10.251.11.6', '10.251.12.9', '89.0.0.1'],
            'ipv6'        => ['2a07:9c0:b:500::/56', '2a07:9c0:b:600::/56', '2a07:9c0:c:900::/56'],
            'assignments' => [
                ['profile_id' => $fast, 'ipv4' => ['10.251.11.5', '10.251.11.6'], 'ipv6' => ['2a07:9c0:b:500::/56', '2a07:9c0:b:600::/56']],
                ['profile_id' => $slow, 'ipv4' => ['10.251.12.9', '89.0.0.1'], 'ipv6' => ['2a07:9c0:c:900::/56']],
            ],
        ], $m);
        $this->assertNull(collect($json['members'])->firstWhere('member_id', $noSpeed['member']), 'bez rychlosti do QoS nepatří');
        $this->assertNull(collect($json['members'])->firstWhere('member_id', 1), 'sdružení vynecháno');
    }

    // ── port forwardy, NAT, SMTP ─────────────────────────────────────────────

    public function test_port_forwardy_nat_smtp_presne(): void
    {
        DB::table('public_port_forwards')->delete();
        DB::table('public_ip_nat_1to1')->delete();
        DB::table('smtp_exceptions')->delete();
        $pf = fn (array $r) => DB::table('public_port_forwards')->insertGetId($r + ['enabled' => 1, 'created' => now(), 'modified' => now()]);
        $a = $pf(['public_ip' => '89.0.0.2', 'public_port_from' => 8080, 'public_port_to' => 8080, 'private_ip' => '10.251.13.2', 'private_port_from' => 80, 'private_port_to' => 80, 'protocol' => 'TCP']);
        $b = $pf(['public_ip' => '89.0.0.1', 'public_port_from' => 5000, 'public_port_to' => 5010, 'private_ip' => '10.251.13.1', 'private_port_from' => 6000, 'private_port_to' => 6010, 'protocol' => 'udp']);
        $pf(['public_ip' => '89.0.0.3', 'public_port_from' => 1, 'public_port_to' => 5, 'private_ip' => '10.251.13.3', 'private_port_from' => 1, 'private_port_to' => 9, 'protocol' => 'tcp']); // nesouměrný rozsah
        $pf(['public_ip' => '89.0.0.4', 'public_port_from' => 1, 'public_port_to' => 1, 'private_ip' => '10.251.13.4', 'private_port_from' => 1, 'private_port_to' => 1, 'protocol' => 'tcp', 'enabled' => 0]);

        $this->assertSame(['udp;89.0.0.1;5000:5010;10.251.13.1;6000-6010', 'tcp;89.0.0.2;8080;10.251.13.2;80'], $this->lines($this->gw('public-port-forwards-txt')),
            'nesouměrný rozsah a vypnuté vynechány');
        $json = $this->gw('public-port-forwards-json')->json('public_port_forwards');
        $this->assertSame([$b, $a], array_slice(array_column($json, 'id'), 0, 2));
        $this->assertCount(3, $json, 'JSON bez kontroly rozsahu, jen enabled');
        $this->assertSame(['id', 'public_ip', 'public_port_from', 'public_port_to', 'private_ip', 'private_port_from', 'private_port_to', 'protocol'], array_keys($json[0]));

        $nat = fn (array $r) => DB::table('public_ip_nat_1to1')->insertGetId($r + ['enabled' => 1, 'created' => now(), 'modified' => now()]);
        $nat(['public_ip' => '89.0.1.2', 'private_ip' => '10.251.14.2', 'scope' => 'igw2', 'comment' => "Firma\nřádek"]);
        $nat(['public_ip' => '89.0.1.1', 'private_ip' => '10.251.14.1', 'scope' => 'igw1', 'comment' => '']);
        $nat(['public_ip' => '89.0.1.3', 'private_ip' => '', 'scope' => 'igw1']);
        $this->assertSame(['89.0.1.1;10.251.14.1', '# Firma řádek', '89.0.1.2;10.251.14.2'], $this->lines($this->gw('public-ip-nat-1to1-txt')));
        $this->assertSame(['# Firma řádek', '89.0.1.2;10.251.14.2'], $this->lines($this->gw('public-ip-nat-1to1-txt', ['scope' => 'igw2'])));
        $this->gw('public-ip-nat-1to1-txt', ['scope' => 'a b'])->assertStatus(400);
        $this->assertSame(['igw1', 'igw2'], array_column($this->gw('public-ip-nat-1to1-json')->json('public_ip_nat_1to1'), 'scope'));

        DB::table('smtp_exceptions')->insert([['intip' => '10.251.15.20', 'user' => 'b', 'datum' => '2026-01-01'], ['intip' => '10.251.15.3', 'user' => 'a', 'datum' => '2026-01-02']]);
        $this->assertSame(['10.251.15.3', '10.251.15.20'], $this->lines($this->gw('smtp-exceptions-txt')), 'řazeno numericky podle IP');
        $this->assertSame(['10.251.15.3', '10.251.15.20'], array_column($this->gw('smtp-exceptions-json')->json('smtp_exceptions'), 'intip'));
    }
}
