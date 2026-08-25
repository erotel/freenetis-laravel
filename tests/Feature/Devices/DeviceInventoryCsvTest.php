<?php

namespace Tests\Feature\Devices;

use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * CSV inventář všech zařízení (DeviceController::inventoryCsv):
 *  - vyžaduje view_all (jinak 403),
 *  - obsahuje hlavičku + řádek se zařízením (rozhraní/MAC/IP),
 *  - credentialy (login/heslo/wpa) jen s právem na ně.
 */
class DeviceInventoryCsvTest extends DatabaseTestCase
{
    private function acl(bool $allowAll, bool $allowCreds = true): void
    {
        $this->mock(AclService::class)->shouldReceive('hasAccess')->andReturnUsing(
            function ($userId, $aco, $section, $value) use ($allowAll, $allowCreds) {
                if ($section === 'Devices_Controller' && in_array($value, ['login', 'password'], true)) {
                    return $allowCreds;
                }
                if ($section === 'Devices_Controller' && $value === 'devices') {
                    return $allowAll;
                }
                return true;
            }
        );
    }

    public function test_bez_view_all_403(): void
    {
        $this->acl(false);
        $this->actingAs(User::find(1))
            ->get(route('devices.inventory_export'))
            ->assertForbidden();
    }

    public function test_csv_obsahuje_hlavicku_a_zarizeni(): void
    {
        $this->acl(true);
        // Zařízení, které má MAC + IP (ať ověříme, že se rozhraní propíšou).
        $row = DB::table('devices as d')
            ->join('ifaces as i', 'i.device_id', '=', 'd.id')
            ->join('ip_addresses as ip', 'ip.iface_id', '=', 'i.id')
            ->whereNotNull('i.mac')
            ->select('d.id', 'd.name', 'i.mac')
            ->first();
        if (!$row) {
            $this->markTestSkipped('žádné zařízení s MAC a IP');
        }

        $resp = $this->actingAs(User::find(1))->get(route('devices.inventory_export'));
        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $resp->streamedContent();
        $this->assertStringContainsString('Rozhraní (detail)', $csv, 'hlavička');
        $this->assertStringContainsString('MAC adresy', $csv, 'hlavička MAC');
        $this->assertStringContainsString((string) $row->id, $csv, 'ID zařízení v CSV');
        $this->assertStringContainsString($row->mac, $csv, 'MAC zařízení v CSV');
    }
}
