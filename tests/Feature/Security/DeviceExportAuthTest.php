<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Regrese (audit 2026-08-18): DeviceController::export() měl legacy auth větev
 * „request přichází z IP zařízení" ($fromDevice). To umožňovalo komukoliv, kdo
 * volal z IP zaregistrovaného zařízení, stáhnout DHCP export — a ten přes
 * buildDhcpServers() vydává MAC + jméno člena + IP všech sousedů na sdíleném
 * subnetu (cross-member disclosure). DHCP servery dnes jezdí přes dhcp_api_token,
 * takže IP-based větev byla odstraněna. Auth = jen token nebo přihlášený s ACL.
 */
class DeviceExportAuthTest extends DatabaseTestCase
{
    private const FORMAT = 'mikrotik-ip-dhcp-server';
    private string $token = 'test-dhcp-token-abc123';
    private int $deviceId;
    private string $deviceIp;

    protected function setUp(): void
    {
        parent::setUp();

        $row = DB::table('ip_addresses')
            ->join('ifaces', 'ifaces.id', '=', 'ip_addresses.iface_id')
            ->whereNotNull('ifaces.device_id')
            ->whereNotNull('ip_addresses.ip_address')
            ->select('ifaces.device_id as did', 'ip_addresses.ip_address as ip')
            ->first();

        if (!$row) {
            $this->markTestSkipped('žádné zařízení s IP');
        }

        $this->deviceId = (int) $row->did;
        $this->deviceIp = $row->ip;
        Setting::set('dhcp_api_token', $this->token);
    }

    private function exportUrl(array $query = []): string
    {
        return route('devices.export', ['id' => $this->deviceId, 'format' => self::FORMAT])
            . (($q = http_build_query($query)) ? '?' . $q : '');
    }

    public function test_volani_z_ip_zarizeni_bez_tokenu_uz_neni_autorizovane(): void
    {
        // Klíčový test opravy: request přichází PŘÍMO z IP zařízení, ale bez
        // tokenu → dřív 200 (fromDevice), teď musí být 403.
        $r = $this->withServerVariables(['REMOTE_ADDR' => $this->deviceIp])
            ->get($this->exportUrl());

        $r->assertForbidden();
    }

    public function test_platny_token_projde(): void
    {
        $r = $this->withServerVariables(['REMOTE_ADDR' => $this->deviceIp])
            ->get($this->exportUrl(['token' => $this->token, 'forced' => 1]));

        $r->assertOk();
    }

    public function test_spatny_token_403(): void
    {
        $r = $this->withServerVariables(['REMOTE_ADDR' => $this->deviceIp])
            ->get($this->exportUrl(['token' => 'wrong-token', 'forced' => 1]));

        $r->assertForbidden();
    }
}
