<?php

namespace Tests\Feature\Devices;

use App\Models\Iface;
use App\Models\PppoeSecret;
use App\Services\PppoeSecretService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Generátor PPPoE credentialů per přípojka (iface):
 *  - username = variabilní symbol člena (fallback member_id),
 *  - u víc přípojek téhož VS suffix -2, -3 … (username je UNIQUE),
 *  - heslo silné náhodné, idempotence (opakované volání heslo nemění),
 *  - rotace hesla nechá username.
 */
class PppoeSecretServiceTest extends DatabaseTestCase
{
    private function svc(): PppoeSecretService
    {
        return app(PppoeSecretService::class);
    }

    /** Iface, jehož člen má variabilní symbol → vrací [iface_id, vs]. */
    private function ifaceWithVs(): ?object
    {
        return DB::table('ifaces as i')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->join('accounts as a', 'a.member_id', '=', 'u.member_id')
            ->join('variable_symbols as vs', 'vs.account_id', '=', 'a.id')
            ->select('i.id as iface_id', 'vs.variable_symbol as vs')
            ->first();
    }

    public function test_username_je_variabilni_symbol_a_heslo_silne(): void
    {
        $row = $this->ifaceWithVs();
        if (!$row) {
            $this->markTestSkipped('žádná iface s variabilním symbolem');
        }

        $secret = $this->svc()->ensureForIface(Iface::find($row->iface_id));

        $this->assertNotNull($secret);
        $this->assertSame($row->iface_id, $secret->iface_id);
        $this->assertSame((string) $row->vs, $secret->username, 'username = variabilní symbol');
        $this->assertSame(16, strlen($secret->secret), 'heslo má 16 znaků');
        $this->assertTrue($secret->enabled);
    }

    public function test_opakovane_volani_nemeni_heslo(): void
    {
        $row = $this->ifaceWithVs();
        if (!$row) {
            $this->markTestSkipped('žádná iface s variabilním symbolem');
        }
        $iface = Iface::find($row->iface_id);

        $first  = $this->svc()->ensureForIface($iface)->secret;
        $second = $this->svc()->ensureForIface($iface->fresh())->secret;

        $this->assertSame($first, $second, 'idempotence: heslo se znovu negeneruje');
    }

    public function test_rotace_zmeni_heslo_ale_ne_username(): void
    {
        $row = $this->ifaceWithVs();
        if (!$row) {
            $this->markTestSkipped('žádná iface s variabilním symbolem');
        }
        $iface = Iface::find($row->iface_id);

        $before = $this->svc()->ensureForIface($iface);
        $user   = $before->username;
        $old    = $before->secret;

        $after = $this->svc()->rotateSecret($iface);

        $this->assertSame($user, $after->username, 'rotace nechá username');
        $this->assertNotSame($old, $after->secret, 'rotace změní heslo');
    }

    public function test_kolize_username_dostane_suffix(): void
    {
        $row = $this->ifaceWithVs();
        if (!$row) {
            $this->markTestSkipped('žádná iface s variabilním symbolem');
        }
        // Obsadíme čistý VS jinou (fiktivní) iface → náš musí dostat {vs}-2.
        $otherIfaceId = $row->iface_id + 9_000_000; // neexistující iface, jen kolize username
        PppoeSecret::create([
            'iface_id' => $otherIfaceId,
            'username' => (string) $row->vs,
            'secret'   => 'placeholderpwd123',
            'enabled'  => true,
        ]);

        $secret = $this->svc()->ensureForIface(Iface::find($row->iface_id));

        $this->assertSame($row->vs . '-2', $secret->username, 'kolidující VS dostane suffix -2');
    }
}
