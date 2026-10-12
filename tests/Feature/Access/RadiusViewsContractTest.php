<?php

namespace Tests\Feature\Access;

use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Kontrakt RADIUS views pro Kea (app/Domains/Access/INFRASTRUKTURA.md):
 *  - *_kea4_v: username = CONCAT(remote_id, circuit_id) binárně → Framed-IP,
 *    jen IP rozhraní v dhcp+ipoe subnetu (gateway=0, 10.%);
 *  - *_kea6mac_v: username = MAC velkými písmeny bez ':' → PD, jen když má
 *    MAC právě jeden prefix.
 */
class RadiusViewsContractTest extends DatabaseTestCase
{
    private int $deviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deviceId = (int) DB::table('devices')->value('id');
        if (!$this->deviceId) {
            $this->markTestSkipped('chybí zařízení');
        }
        // Dotazujeme se jako FreeRADIUS: výchozí sql_mode serveru. Laravel
        // (strict) zapíná ONLY_FULL_GROUP_BY, se kterým *_kea6mac_v neprojde.
        DB::statement('SET SESSION sql_mode = @@GLOBAL.sql_mode');
    }

    private function iface(?string $mac): int
    {
        return (int) DB::table('ifaces')->insertGetId(['device_id' => $this->deviceId, 'type' => 1, 'name' => 'char', 'mac' => $mac]);
    }

    private function subnet(int $ipoe): int
    {
        return (int) DB::table('subnets')->insertGetId([
            'name' => 'char kea', 'network_address' => '10.250.7.0', 'netmask' => '255.255.255.0', 'dhcp' => 1, 'ipoe' => $ipoe,
        ]);
    }

    public function test_kea4_klicuje_remote_plus_circuit_jen_v_ipoe(): void
    {
        $remote  = "\x00\x1a\xff\xc3\x28\x9e";
        $circuit = '__selftest_kea4 ' . uniqid();

        $if = $this->iface(null);
        DB::table('ip_addresses')->insert(['iface_id' => $if, 'subnet_id' => $this->subnet(1), 'ip_address' => '10.250.7.20', 'gateway' => 0]);
        DB::table('line_ids')->insert(['circuit_id' => $circuit, 'remote_id' => $remote, 'iface_id' => $if, 'source' => 'manual']);

        $hex = strtoupper(bin2hex($remote . $circuit));
        $reply = DB::table('radreply_kea4_v')->whereRaw('HEX(username) = ?', [$hex])->get();
        $this->assertCount(1, $reply);
        $this->assertSame('Framed-IP-Address', $reply[0]->attribute);
        $this->assertSame('10.250.7.20', $reply[0]->value);
        $this->assertSame(1, DB::table('radcheck_kea4_v')->whereRaw('HEX(username) = ?', [$hex])->count());

        // stejné mapování v ne-IPoE subnetu → Kea nic nedostane
        $if2 = $this->iface(null);
        DB::table('ip_addresses')->insert(['iface_id' => $if2, 'subnet_id' => $this->subnet(0), 'ip_address' => '10.250.7.21', 'gateway' => 0]);
        DB::table('line_ids')->insert(['circuit_id' => $circuit . '-x', 'remote_id' => '', 'iface_id' => $if2, 'source' => 'manual']);
        $this->assertSame(0, DB::table('radreply_kea4_v')->where('username', $circuit . '-x')->count());
    }

    public function test_kea6mac_klicuje_mac_bez_dvojtecek_jen_jednoznacny_prefix(): void
    {
        $if = $this->iface('02:c6:aa:bb:cc:01');
        DB::table('ip6_addresses')->insert(['iface_id' => $if, 'ip_address' => '2a07:9c0:fff1::/56']);

        $reply = DB::table('radreply_kea6mac_v')->where('username', '02C6AABBCC01')->get();
        $this->assertCount(1, $reply);
        $this->assertSame('Delegated-IPv6-Prefix', $reply[0]->attribute);
        $this->assertSame('2a07:9c0:fff1::/56', $reply[0]->value);

        // druhý prefix na stejné MAC → nejednoznačné → Kea nedostane nic
        DB::table('ip6_addresses')->insert(['iface_id' => $if, 'ip_address' => '2a07:9c0:fff2::/56']);
        $this->assertSame(0, DB::table('radreply_kea6mac_v')->where('username', '02C6AABBCC01')->count());
        $this->assertSame(0, DB::table('radcheck_kea6mac_v')->where('username', '02C6AABBCC01')->count());
    }
}
