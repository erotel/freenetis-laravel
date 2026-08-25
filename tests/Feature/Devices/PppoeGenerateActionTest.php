<?php

namespace Tests\Feature\Devices;

use App\Models\PppoeSecret;
use App\Models\Setting;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Akce generování PPPoE credentialu (DeviceController::pppoeGenerate):
 *  - vyžaduje zapnutý modul (Setting pppoe_enabled) → jinak 403,
 *  - vyžaduje právo edit_all + view password → jinak 403,
 *  - při splnění vytvoří credential pro rozhraní.
 */
class PppoeGenerateActionTest extends DatabaseTestCase
{
    private int $ifaceId;

    protected function setUp(): void
    {
        parent::setUp();
        $row = DB::table('ifaces as i')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->whereNotNull('u.member_id')
            ->select('i.id as iface_id')
            ->first();
        if (!$row) {
            $this->markTestSkipped('žádná iface s členem');
        }
        $this->ifaceId = (int) $row->iface_id;
    }

    private function grantAcl(bool $allowPassword = true): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(
            function ($userId, $aco, $section, $value) use ($allowPassword) {
                if ($section === 'Devices_Controller' && $value === 'password') {
                    return $allowPassword;
                }
                return true;
            }
        );
    }

    private function generate()
    {
        return $this->actingAs(User::find(1))
            ->post(route('devices.pppoe.generate', $this->ifaceId));
    }

    public function test_vypnuty_modul_vrati_403(): void
    {
        Setting::set('pppoe_enabled', 0);
        $this->grantAcl(true);

        $this->generate()->assertForbidden();
        $this->assertNull(PppoeSecret::find($this->ifaceId));
    }

    public function test_bez_prava_na_heslo_403(): void
    {
        Setting::set('pppoe_enabled', 1);
        $this->grantAcl(false);

        $this->generate()->assertForbidden();
        $this->assertNull(PppoeSecret::find($this->ifaceId));
    }

    public function test_se_zapnutym_modulem_a_pravem_vytvori_credential(): void
    {
        Setting::set('pppoe_enabled', 1);
        $this->grantAcl(true);

        $this->generate()->assertRedirect();

        $secret = PppoeSecret::find($this->ifaceId);
        $this->assertNotNull($secret, 'credential vznikl');
        $this->assertNotEmpty($secret->username);
        $this->assertSame(16, strlen($secret->secret));
    }
}
