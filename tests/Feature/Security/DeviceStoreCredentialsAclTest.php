<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Regrese (audit 2026-08-18): DeviceController::update() chrání login/heslo
 * zařízení jemným právem view_all#login / view_all#password, ale store() je
 * dřív bral vždy (a create formulář je zobrazoval všem). Uživatel s právem
 * zakládat zařízení, ale bez práva na login/heslo (např. skupina Tech4), tak
 * mohl nastavit credentials při založení, přestože u editace na to právo nemá.
 *
 * Oprava: store() přidá login/password do pravidel jen s daným jemným právem
 * (zrcadlo update()); create formulář pole taky skrývá.
 */
class DeviceStoreCredentialsAclTest extends DatabaseTestCase
{
    private int $userId = 1;
    private int $memberId = 1;
    private int $deviceType = 7; // enum_types "PC"
    private string $devName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->devName = 'ACLTEST-' . uniqid();
    }

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

    private function storeDevice()
    {
        return $this->actingAs(User::find($this->userId))->post(route('devices.store'), [
            'member_id' => $this->memberId,
            'name'      => $this->devName,
            'type'      => $this->deviceType,
            'login'     => 'tajny_login',
            'password'  => 'tajne_heslo',
        ]);
    }

    public function test_bez_prava_na_credentials_se_login_heslo_zahodi(): void
    {
        $this->grantAcl(false); // smí zakládat zařízení, NEsmí login/heslo

        $this->storeDevice();

        $dev = DB::table('devices')->where('name', $this->devName)->first(['login', 'password']);
        $this->assertNotNull($dev, 'zařízení mělo vzniknout');
        $this->assertNull($dev->login, 'login se nesmí uložit bez práva');
        $this->assertNull($dev->password, 'heslo se nesmí uložit bez práva');
    }

    public function test_s_pravem_se_login_heslo_ulozi(): void
    {
        $this->grantAcl(true); // plné právo včetně login/heslo

        $this->storeDevice();

        // Přes model kvůli dešifrování (password má encrypted cast).
        $dev = \App\Models\Device::where('name', $this->devName)->first();
        $this->assertNotNull($dev);
        $this->assertSame('tajny_login', $dev->login);
        $this->assertSame('tajne_heslo', $dev->password);
    }
}
