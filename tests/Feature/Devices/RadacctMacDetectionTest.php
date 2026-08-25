<?php

namespace Tests\Feature\Devices;

use App\Http\Controllers\ConnectionRequestController;
use App\Models\Setting;
use App\Services\SnmpMacDetector;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Detekce MAC pro connection request: se zapnutým PPPoE modulem se bere z RADIUS
 * accountingu (radacct: pool-IP → Calling-Station-Id aktivní session), jinak/při
 * nenalezení fallback na SNMP. Nahrazuje SNMP u PPPoE onboardingu (bod 4 návrhu).
 */
class RadacctMacDetectionTest extends DatabaseTestCase
{
    private const IP  = '10.199.199.199';
    private const MAC = 'de:ad:be:ef:00:11';

    private Closure $detectMac;

    protected function setUp(): void
    {
        parent::setUp();
        $ctrl = (new \ReflectionClass(ConnectionRequestController::class))->newInstanceWithoutConstructor();
        $this->detectMac = Closure::bind(
            fn (int $subnetId, string $ip) => $this->detectMac($subnetId, $ip),
            $ctrl,
            ConnectionRequestController::class
        );
    }

    private function insertSession(string $ip, string $mac): void
    {
        DB::table('radacct')->insert([
            'acctsessionid'    => '__selftest_det_' . substr(md5($ip . $mac), 0, 8),
            'acctuniqueid'     => substr(md5($ip . $mac . 'u'), 0, 32),
            'username'         => 'admin',
            'framedipaddress'  => $ip,
            'callingstationid' => $mac,
            'nasipaddress'     => '10.133.31.70',
            'acctstarttime'    => now(),
            'acctstoptime'     => null,
        ]);
    }

    public function test_radacct_ma_prednost_a_normalizuje_na_velka_pismena(): void
    {
        Setting::set('pppoe_enabled', 1);
        $this->insertSession(self::IP, self::MAC);
        // SNMP se nesmí ani zavolat — kdyby ano, vrátí sentinel, který by test odhalil.
        $this->mock(SnmpMacDetector::class)
            ->shouldReceive('detectForSubnet')->andReturn('SNMP-NEMELO-BYT');

        $mac = ($this->detectMac)(123, self::IP);

        $this->assertSame('DE:AD:BE:EF:00:11', $mac, 'MAC z radacct, velkými písmeny');
    }

    public function test_bez_zaznamu_v_radacct_fallback_na_snmp(): void
    {
        Setting::set('pppoe_enabled', 1); // modul zapnutý, ale pro tuhle IP nic v radacct
        $this->mock(SnmpMacDetector::class)
            ->shouldReceive('detectForSubnet')->andReturn('AA:BB:CC:DD:EE:FF');

        $mac = ($this->detectMac)(123, '10.199.199.200');

        $this->assertSame('AA:BB:CC:DD:EE:FF', $mac, 'fallback na SNMP');
    }

    public function test_vypnuty_modul_ignoruje_radacct_a_jde_na_snmp(): void
    {
        Setting::set('pppoe_enabled', 0);
        $this->insertSession(self::IP, self::MAC); // v radacct je, ale modul vypnutý
        $this->mock(SnmpMacDetector::class)
            ->shouldReceive('detectForSubnet')->andReturn('AA:BB:CC:DD:EE:FF');

        $mac = ($this->detectMac)(123, self::IP);

        $this->assertSame('AA:BB:CC:DD:EE:FF', $mac, 'vypnutý modul → SNMP, ne radacct');
    }
}
