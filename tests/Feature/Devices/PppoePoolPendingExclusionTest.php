<?php

namespace Tests\Feature\Devices;

use App\Http\Controllers\DeviceController;
use App\Models\ConnectionRequest;
use App\Models\Device;
use App\Models\Setting;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Pojistka proti konfliktu: se zapnutým PPPoE modulem RADIUS servíruje IP z
 * čekající žádosti jako statickou Framed-IP UŽ PŘED schválením. DHCP/PPPoE pool
 * (buildDhcpServers) proto musí tuhle IP vyříznout z ranges — jinak by ji přidělil
 * dalšímu bootstrap klientovi = adresní konflikt.
 */
class PppoePoolPendingExclusionTest extends DatabaseTestCase
{
    private Closure $build;

    protected function setUp(): void
    {
        parent::setUp();
        $ctrl = (new \ReflectionClass(DeviceController::class))->newInstanceWithoutConstructor();
        $this->build = Closure::bind(
            fn (Device $d) => $this->buildDhcpServers($d),
            $ctrl,
            DeviceController::class
        );
    }

    /** Najde DHCP-serverové zařízení (iface s gateway IP v dhcp subnetu). */
    private function dhcpDevice(): ?object
    {
        return DB::table('ip_addresses as ip')
            ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->join('subnets as s', 's.id', '=', 'ip.subnet_id')
            ->where('ip.gateway', 1)->where('s.dhcp', 1)
            ->whereNotNull('i.device_id')
            ->select('i.device_id', 'ip.ip_address as gw')
            ->first();
    }

    /** True když $ipLong spadá do některého "start-end" rozsahu. */
    private function inRanges(array $ranges, int $ipLong): bool
    {
        foreach ($ranges as $r) {
            [$a, $b] = explode('-', $r);
            if ($ipLong >= ip2long($a) && $ipLong <= ip2long($b)) {
                return true;
            }
        }
        return false;
    }

    public function test_cekajici_zadost_vyrizne_ip_z_poolu(): void
    {
        Setting::set('pppoe_enabled', 1);
        $dev = $this->dhcpDevice();
        if (!$dev) {
            $this->markTestSkipped('žádné DHCP zařízení');
        }
        $device = Device::find($dev->device_id);

        // Baseline ranges pro server s touto gateway.
        $baseline = collect(($this->build)($device))->firstWhere('gateway', $dev->gw);
        if (!$baseline || empty($baseline['ranges'])) {
            $this->markTestSkipped('server nemá volné ranges');
        }
        // Vezmi první volnou IP z poolu (začátek prvního rozsahu).
        $freeIp   = explode('-', $baseline['ranges'][0])[0];
        $freeLong = ip2long($freeIp);
        $this->assertTrue($this->inRanges($baseline['ranges'], $freeLong), 'IP je v poolu před žádostí');

        // Přidej čekající žádost s touhle IP.
        ConnectionRequest::create([
            'member_id'      => 2,
            'added_user_id'  => 1,
            'state'          => ConnectionRequest::STATE_UNDECIDED,
            'created_at'     => now(),
            'ip_address'     => $freeIp,
            'pppoe_username' => '__selftest_excl',
            'pppoe_secret'   => 'placeholderpwd12',
        ]);

        // Znovu — IP musí z ranges zmizet.
        $after = collect(($this->build)($device->fresh()))->firstWhere('gateway', $dev->gw);
        $this->assertFalse(
            $this->inRanges($after['ranges'], $freeLong),
            'IP čekající žádosti je z poolu vyříznutá'
        );
    }

    public function test_vypnuty_modul_ip_zadosti_nevyrizne(): void
    {
        Setting::set('pppoe_enabled', 0);
        $dev = $this->dhcpDevice();
        if (!$dev) {
            $this->markTestSkipped('žádné DHCP zařízení');
        }
        $device = Device::find($dev->device_id);

        $baseline = collect(($this->build)($device))->firstWhere('gateway', $dev->gw);
        if (!$baseline || empty($baseline['ranges'])) {
            $this->markTestSkipped('server nemá volné ranges');
        }
        $freeIp   = explode('-', $baseline['ranges'][0])[0];
        $freeLong = ip2long($freeIp);

        ConnectionRequest::create([
            'member_id'      => 2,
            'added_user_id'  => 1,
            'state'          => ConnectionRequest::STATE_UNDECIDED,
            'created_at'     => now(),
            'ip_address'     => $freeIp,
            'pppoe_username' => '__selftest_excl2',
            'pppoe_secret'   => 'placeholderpwd12',
        ]);

        // Modul vypnutý → žádost pool neovlivní (IP zůstává v ranges).
        $after = collect(($this->build)($device->fresh()))->firstWhere('gateway', $dev->gw);
        $this->assertTrue(
            $this->inRanges($after['ranges'], $freeLong),
            'vypnutý modul: IP žádosti zůstává v poolu'
        );
    }
}
