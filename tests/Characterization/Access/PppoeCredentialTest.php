<?php

namespace Tests\Characterization\Access;

use App\Models\Iface;
use App\Models\PppoeSecret;
use App\Services\PppoeSecretService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace PPPoE credentialů — doplňuje tests/Feature/Devices/Pppoe*
 * o cesty, které tam chybí (iface bez člena, člen bez VS, dorovnání username,
 * rotace bez existujícího credentialu, build bez persistence). `pppoe_secrets`
 * je kontrakt pro RADIUS views (radcheck/radreply_pppoe_v).
 */
class PppoeCredentialTest extends DatabaseTestCase
{
    private int $apId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        if (!$this->apId) {
            $this->markTestSkipped('chybí adresní bod');
        }
    }

    private function svc(): PppoeSecretService
    {
        return app(PppoeSecretService::class);
    }

    /** Člen (bez účtu → bez VS) + uživatel + zařízení + iface. Vrací [memberId, ifaceId]. */
    private function connection(bool $withMember = true): array
    {
        $memberId = null;
        $userId   = null;
        if ($withMember) {
            $memberId = (int) DB::table('members')->insertGetId([
                'name' => 'Char PPPoE', 'type' => 2, 'entrance_date' => '2026-01-01',
                'leaving_date' => '9999-12-31', 'registration' => 1, 'locked' => 0,
                'address_point_id' => $this->apId,
            ]);
            $userId = (int) DB::table('users')->insertGetId([
                'member_id' => $memberId, 'login' => 'pppoe_' . uniqid(), 'password' => Hash::make('x'),
                'type' => 1, 'application_password' => 'xxxxxxxx', 'settings' => '',
                'name' => 'Char', 'surname' => 'Pppoe', 'comment' => '',
            ]);
        }
        $deviceId = (int) DB::table('devices')->insertGetId(['name' => 'char-pppoe', 'type' => 1, 'user_id' => $userId]);
        $ifaceId  = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'device_id' => $deviceId, 'name' => 'char']);
        return [$memberId, $ifaceId];
    }

    public function test_iface_bez_clena_nevytvori_nic(): void
    {
        [, $ifaceId] = $this->connection(false);

        $this->assertNull($this->svc()->ensureForIface(Iface::find($ifaceId)));
        $this->assertNull(PppoeSecret::find($ifaceId));
    }

    public function test_clen_bez_vs_ma_username_member_id(): void
    {
        [$memberId, $ifaceId] = $this->connection();
        DB::table('pppoe_secrets')->where('username', (string) $memberId)->delete();

        $s = $this->svc()->ensureForIface(Iface::find($ifaceId));

        $this->assertSame((string) $memberId, $s->username);
        $this->assertMatchesRegularExpression('/^[a-km-zA-HJ-NP-Z2-9]{16}$/', $s->secret, 'abeceda bez 0/O/1/l/I');
    }

    public function test_existujici_credential_dorovna_username_heslo_necha(): void
    {
        [$memberId, $ifaceId] = $this->connection();
        DB::table('pppoe_secrets')->where('username', (string) $memberId)->delete();
        PppoeSecret::create(['iface_id' => $ifaceId, 'username' => 'stary-' . $memberId, 'secret' => 'puvodniHeslo2345', 'enabled' => true]);

        $s = $this->svc()->ensureForIface(Iface::find($ifaceId));

        $this->assertSame((string) $memberId, $s->username);
        $this->assertSame('puvodniHeslo2345', PppoeSecret::find($ifaceId)->secret);
    }

    public function test_rotace_bez_credentialu_ho_vytvori(): void
    {
        [$memberId, $ifaceId] = $this->connection();
        DB::table('pppoe_secrets')->where('username', (string) $memberId)->delete();

        $s = $this->svc()->rotateSecret(Iface::find($ifaceId));

        $this->assertNotNull($s);
        $this->assertSame((string) $memberId, PppoeSecret::find($ifaceId)->username);
    }

    public function test_build_credential_nic_neulozi(): void
    {
        [$memberId] = $this->connection();
        $before = DB::table('pppoe_secrets')->count();

        $c = $this->svc()->buildCredential($memberId);

        $this->assertSame((string) $memberId, $c['username']);
        $this->assertSame(16, strlen($c['secret']));
        $this->assertSame($before, DB::table('pppoe_secrets')->count());
    }

    public function test_adopt_je_idempotentni_a_zapne_credential(): void
    {
        [, $ifaceId] = $this->connection();
        PppoeSecret::create(['iface_id' => $ifaceId, 'username' => 'x-' . $ifaceId, 'secret' => 'a', 'enabled' => false]);

        $this->svc()->adoptFromRequest(Iface::find($ifaceId), 'adopt-' . $ifaceId, 'hesloZeZadosti23');

        $s = PppoeSecret::find($ifaceId);
        $this->assertSame('adopt-' . $ifaceId, $s->username);
        $this->assertSame('hesloZeZadosti23', $s->secret);
        $this->assertTrue($s->enabled);
        $this->assertSame(1, PppoeSecret::where('iface_id', $ifaceId)->count());
    }
}
