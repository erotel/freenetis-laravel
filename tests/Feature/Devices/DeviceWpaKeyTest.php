<?php

namespace Tests\Feature\Devices;

use App\Models\Device;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Šifrovací klíč WPA2 (devices.wpa_key) pro přístupové body (type = AP, id 66):
 *  - ukládá se šifrovaně, jen s právem na heslo zařízení (Devices_Controller#password),
 *  - jen u typu AP (u ostatních typů se zahodí),
 *  - vyžaduje délku 16–63 (doporučení NIS2 pro sdílený klíč).
 */
class DeviceWpaKeyTest extends DatabaseTestCase
{
    private int $userId = 1;
    private int $memberId = 1;
    private const AP = Device::AP_TYPE_ID; // 66
    private const PC = 7;
    private const KEY = 'Wpa2SilnyKlic123456'; // 19 znaků

    private function grantAcl(bool $allowCreds): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(
            function ($userId, $aco, $section, $value) use ($allowCreds) {
                if ($section === 'Devices_Controller' && in_array($value, ['login', 'password'], true)) {
                    return $allowCreds;
                }
                return true;
            }
        );
    }

    private function store(array $override = [])
    {
        return $this->actingAs(User::find($this->userId))->post(route('devices.store'), array_merge([
            'member_id' => $this->memberId,
            'name'      => 'WPATEST-' . uniqid(),
            'type'      => self::AP,
            'wpa_key'   => self::KEY,
        ], $override));
    }

    public function test_ap_ulozi_wpa_klic_sifrovane(): void
    {
        $this->grantAcl(true);
        $name = 'WPATEST-' . uniqid();

        $this->store(['name' => $name, 'type' => self::AP, 'wpa_key' => self::KEY]);

        $dev = Device::where('name', $name)->first(); // model dešifruje
        $this->assertNotNull($dev);
        $this->assertSame(self::KEY, $dev->wpa_key, 'u AP se klíč uloží (a dešifruje)');

        // V DB je uložený šifrovaně, ne plaintextem.
        $raw = DB::table('devices')->where('name', $name)->value('wpa_key');
        $this->assertNotSame(self::KEY, $raw, 'v DB nesmí být plaintext');
    }

    public function test_neap_typ_wpa_klic_zahodi(): void
    {
        $this->grantAcl(true);
        $name = 'WPATEST-' . uniqid();

        $this->store(['name' => $name, 'type' => self::PC, 'wpa_key' => self::KEY]);

        $dev = Device::where('name', $name)->first();
        $this->assertNotNull($dev);
        $this->assertNull($dev->wpa_key, 'u ne-AP typu se WPA klíč neukládá');
    }

    public function test_kratky_klic_neprojde_validaci(): void
    {
        $this->grantAcl(true);
        $name = 'WPATEST-' . uniqid();

        $this->store(['name' => $name, 'type' => self::AP, 'wpa_key' => 'kratke'])
            ->assertSessionHasErrors('wpa_key');

        $this->assertNull(
            DB::table('devices')->where('name', $name)->first(),
            'zařízení s krátkým klíčem nemá vzniknout'
        );
    }

    public function test_bez_prava_na_heslo_se_wpa_klic_neulozi(): void
    {
        $this->grantAcl(false); // smí zakládat zařízení, NEsmí heslo/WPA
        $name = 'WPATEST-' . uniqid();

        $this->store(['name' => $name, 'type' => self::AP, 'wpa_key' => self::KEY]);

        $dev = Device::where('name', $name)->first();
        $this->assertNotNull($dev, 'zařízení mělo vzniknout');
        $this->assertNull($dev->wpa_key, 'bez práva na heslo se WPA klíč nesmí uložit (podvržený POST)');
    }
}
