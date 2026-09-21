<?php

namespace Tests\Feature\Devices;

use App\Models\Device;
use App\Models\Iface;
use App\Models\IpAddress;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Přesun zařízení na jiného majitele musí přepsat allowed_subnets (přípojná
 * místa / podsítě): nový člen subnet získá, starý ho ztratí, pokud v něm už
 * nemá jinou IP. Regrese na bug, kdy se u nového majitele záznam nevytvořil
 * (DeviceController::update neměnil user_id → nesynchronizoval allowed_subnets).
 */
class DeviceMoveAllowedSubnetTest extends DatabaseTestCase
{
    private int $memberA;
    private int $memberB;
    private int $userA;

    protected function setUp(): void
    {
        parent::setUp();
        $mains = DB::table('users')
            ->where('type', User::MAIN_USER)->where('member_id', '>', 1)
            ->whereNotNull('member_id')
            ->select('member_id', DB::raw('MIN(id) as uid'))
            ->groupBy('member_id')->orderBy('member_id')->limit(2)->get();
        if ($mains->count() < 2) {
            $this->markTestSkipped('nejsou 2 členové s hlavním uživatelem');
        }
        $this->memberA = (int) $mains[0]->member_id;
        $this->userA   = (int) $mains[0]->uid;
        $this->memberB = (int) $mains[1]->member_id;

        // ACL: povol vše (edit_all i jemná práva login/password)
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true);
    }

    private function allowed(int $member, int $subnet): ?object
    {
        return DB::table('allowed_subnets')
            ->where('member_id', $member)->where('subnet_id', $subnet)->first();
    }

    public function test_presun_zarizeni_prepise_allowed_subnets(): void
    {
        $subnet = DB::table('subnets')->insertGetId(['name' => 'devmove-' . uniqid()]);

        // zařízení člena A s IP v subnetu
        $device = Device::create([
            'user_id' => $this->userA,
            'name'    => 'DEVMOVE-' . uniqid(),
            'type'    => 7,
        ]);
        $iface = Iface::create(['device_id' => $device->id, 'type' => 2, 'name' => 'eth0']);
        IpAddress::create([
            'iface_id'   => $iface->id,
            'subnet_id'  => $subnet,
            'ip_address' => '10.99.' . random_int(1, 254) . '.' . random_int(1, 254),
            'gateway'    => 0,
        ]);

        // A má přípojné místo v subnetu, B zatím nic; B ať má dost velký limit
        DB::table('allowed_subnets')->insert([
            'member_id' => $this->memberA, 'subnet_id' => $subnet, 'enabled' => 1,
        ]);
        DB::table('allowed_subnets_counts')->updateOrInsert(
            ['member_id' => $this->memberB], ['count' => 10]
        );

        // precondition
        $this->assertNotNull($this->allowed($this->memberA, $subnet), 'A má subnet před přesunem');
        $this->assertNull($this->allowed($this->memberB, $subnet), 'B ho před přesunem nemá');

        // přesun na člena B
        $this->actingAs(User::find($this->userA))
            ->put(route('devices.update', $device->id), [
                'member_id' => $this->memberB,
                'name'      => $device->name,
                'type'      => 7,
            ]);

        // vlastník se změnil
        $this->assertSame(
            $this->memberB,
            (int) User::find(Device::find($device->id)->user_id)->member_id,
            'zařízení má nového majitele'
        );

        // B subnet získal, A ztratil (neměl v něm jinou IP)
        $b = $this->allowed($this->memberB, $subnet);
        $this->assertNotNull($b, 'nový majitel B má po přesunu allowed_subnets záznam');
        $this->assertSame(1, (int) $b->enabled, 'a je enabled');
        $this->assertNull($this->allowed($this->memberA, $subnet), 'starému A se subnet odebral');
    }
}
