<?php

namespace Tests\Feature\Devices;

use App\Models\ConnectionRequest;
use App\Models\Iface;
use App\Models\PppoeSecret;
use App\Services\PppoeSecretService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Captive onboarding: PPPoE credential se generuje při žádosti o připojení
 * (uloží na connection_request) a při schválení se překlopí do pppoe_secrets.
 *  - username = variabilní symbol, kolize s čekající žádostí → suffix,
 *  - adoptFromRequest zachová username/heslo (instalátor už zadal do CPE),
 *  - heslo je v audit logu redigované ('***').
 */
class PppoeCaptiveOnboardingTest extends DatabaseTestCase
{
    private int $memberId;
    private string $vs;

    protected function setUp(): void
    {
        parent::setUp();
        $row = DB::table('accounts as a')
            ->join('variable_symbols as vs', 'vs.account_id', '=', 'a.id')
            ->join('members as m', 'm.id', '=', 'a.member_id')
            ->select('m.id as member_id', 'vs.variable_symbol as vs')
            ->first();
        if (!$row) {
            $this->markTestSkipped('žádný člen s variabilním symbolem');
        }
        $this->memberId = (int) $row->member_id;
        $this->vs       = (string) $row->vs;
    }

    private function svc(): PppoeSecretService
    {
        return app(PppoeSecretService::class);
    }

    public function test_build_credential_username_je_vs(): void
    {
        $c = $this->svc()->buildCredential($this->memberId);

        $this->assertSame($this->vs, $c['username']);
        $this->assertSame(16, strlen($c['secret']));
    }

    public function test_kolize_s_cekajici_zadosti_da_suffix(): void
    {
        // Čekající žádost už drží čistý VS jako username → další musí dostat -2.
        ConnectionRequest::create([
            'member_id'      => $this->memberId,
            'state'          => ConnectionRequest::STATE_UNDECIDED,
            'created_at'     => now(),
            'pppoe_username' => $this->vs,
            'pppoe_secret'   => 'placeholderpwd12',
        ]);

        $c = $this->svc()->buildCredential($this->memberId);

        $this->assertSame($this->vs . '-2', $c['username']);
    }

    public function test_adopt_from_request_prekopiruje_do_pppoe_secrets(): void
    {
        $iface = Iface::whereNotNull('device_id')->first();
        if (!$iface) {
            $this->markTestSkipped('žádná iface');
        }

        $secret = $this->svc()->adoptFromRequest($iface, $this->vs, 'zadanoInstalatorem1');

        $this->assertSame($iface->id, $secret->iface_id);
        $this->assertSame($this->vs, $secret->username);
        $this->assertSame('zadanoInstalatorem1', $secret->secret);
        $this->assertSame('zadanoInstalatorem1', PppoeSecret::find($iface->id)->secret);
    }

    public function test_heslo_v_audit_logu_redigovano(): void
    {
        $secretValue = 'tajne-' . 'heslo123';
        $cr = ConnectionRequest::create([
            'member_id'      => $this->memberId,
            'state'          => ConnectionRequest::STATE_UNDECIDED,
            'created_at'     => now(),
            'pppoe_username' => $this->vs,
            'pppoe_secret'   => $secretValue,
        ]);

        $row = DB::table('audit_logs')
            ->where('auditable_type', 'connection_requests')
            ->where('auditable_id', $cr->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row, 'audit řádek vznikl');
        $new = json_decode($row->new_values, true);
        $this->assertSame('***', $new['pppoe_secret'] ?? null, 'heslo redigováno');
        $this->assertSame($this->vs, $new['pppoe_username'] ?? null, 'username v cleartextu');
    }
}
