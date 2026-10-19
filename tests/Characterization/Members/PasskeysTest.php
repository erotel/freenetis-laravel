<?php

namespace Tests\Characterization\Members;

use App\Models\User;
use App\Services\WebAuthnService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use Mockery;
use Tests\DatabaseTestCase;

/**
 * Charakterizace passkeys / biometrie (WebAuthnController) před přesunem do
 * domény: výzva v cache pod jednorázovým `state` (TTL 5 min), registrace
 * (vyřazení už registrovaných, duplicita), smazání vlastního klíče (audit),
 * kontrola klíčů k loginu, přihlášení (cílené / bez loginu, neznámý klíč,
 * podezření na klon podle čítače, zamčený člen, MFA splněno, login_logs,
 * návrat do Field). Kryptografie (lbuchs/webauthn) je nahrazená mockem.
 */
class PasskeysTest extends DatabaseTestCase
{
    private int $userId;
    private int $memberId;
    private string $login;
    private $wa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberId = (int) DB::table('members')->insertGetId(['name' => 'Char PK', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $this->login  = 'charpk_' . uniqid();
        $this->userId = (int) DB::table('users')->insertGetId(['member_id' => $this->memberId, 'login' => $this->login, 'password' => Hash::make('Spravne-heslo1'),
            'type' => 1, 'application_password' => 'x', 'settings' => '', 'name' => 'Char', 'surname' => 'Passkey', 'comment' => '']);

        $this->wa = Mockery::mock(WebAuthn::class);
        $this->wa->shouldReceive('getChallenge')->andReturn(new ByteBuffer('challenge-bytes'));
        $svc = Mockery::mock(WebAuthnService::class)->makePartial();
        $svc->shouldReceive('make')->andReturn($this->wa);
        $svc->shouldReceive('userVerification')->andReturn('required');
        $this->app->instance(WebAuthnService::class, $svc);
    }

    private function cred(string $rawId, int $signCount = 0, ?int $userId = null): int
    {
        return (int) DB::table('webauthn_credentials')->insertGetId(['user_id' => $userId ?? $this->userId, 'credential_id' => base64_encode($rawId),
            'public_key' => 'PUBKEY', 'sign_count' => $signCount, 'device_name' => 'char', 'created_at' => now()]);
    }

    private function asUser()
    {
        return $this->actingAs(User::find($this->userId));
    }

    // ── registrace ───────────────────────────────────────────────────────────

    public function test_registrace(): void
    {
        $this->cred('existujici');
        $this->wa->shouldReceive('getCreateArgs')->once()
            ->with((string) $this->userId, $this->login, 'Char Passkey', 60, true, true, null, ['existujici'])
            ->andReturn((object) ['publicKey' => ['rp' => 'x']]);

        $opt = $this->asUser()->postJson(route('webauthn.register.options'), ['password' => 'Spravne-heslo1'])->assertOk()->json();
        $this->assertSame(['rp' => 'x'], $opt['publicKey']);
        $this->assertSame(['challenge' => base64_encode('challenge-bytes'), 'purpose' => 'register:' . $this->userId], cache('webauthn:' . $opt['state']));

        $this->wa->shouldReceive('processCreate')->once()
            ->with('CDJ', 'ATT', Mockery::on(fn ($b) => $b->getBinaryString() === 'challenge-bytes'), true, true, false)
            ->andReturn((object) ['credentialId' => new ByteBuffer('novy-klic'), 'credentialPublicKey' => 'PEM', 'signatureCounter' => 3]);

        $this->asUser()->postJson(route('webauthn.register'), ['state' => $opt['state'], 'device_name' => '  Telefon  ',
            'clientDataJSON' => base64_encode('CDJ'), 'attestationObject' => base64_encode('ATT')])->assertOk()->assertExactJson(['ok' => true]);
        $row = DB::table('webauthn_credentials')->where('credential_id', base64_encode('novy-klic'))->first();
        $this->assertSame([$this->userId, 'PEM', 3, 'Telefon', null], [(int) $row->user_id, $row->public_key, (int) $row->sign_count, $row->device_name, $row->last_used_at]);
        $this->assertNull(cache('webauthn:' . $opt['state']), 'výzva jednorázová');

        $this->asUser()->postJson(route('webauthn.register'), ['state' => $opt['state'], 'clientDataJSON' => 'x', 'attestationObject' => 'x'])
            ->assertStatus(422)->assertExactJson(['error' => 'Vypršela platnost výzvy. Zkuste to znovu.']);
        $this->asUser()->postJson(route('webauthn.register'), [])->assertStatus(422)->assertJsonValidationErrors(['state', 'clientDataJSON', 'attestationObject']);
    }

    public function test_registrace_duplicita_a_chyba_overeni(): void
    {
        $this->cred('dup');
        $this->wa->shouldReceive('getCreateArgs')->andReturn((object) ['publicKey' => []]);
        $calls = 0;
        $this->wa->shouldReceive('processCreate')->andReturnUsing(function () use (&$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('bad attestation');
            }
            return (object) ['credentialId' => 'dup', 'credentialPublicKey' => 'PEM', 'signatureCounter' => null];
        });

        $state = $this->asUser()->postJson(route('webauthn.register.options'), ['password' => 'Spravne-heslo1'])->json('state');
        $this->asUser()->postJson(route('webauthn.register'), ['state' => $state, 'clientDataJSON' => 'eA==', 'attestationObject' => 'eA=='])
            ->assertOk()->assertExactJson(['ok' => true, 'message' => 'Toto zařízení už je registrované.']);
        $this->assertSame(1, DB::table('webauthn_credentials')->where('credential_id', base64_encode('dup'))->count());

        $state = $this->asUser()->postJson(route('webauthn.register.options'), ['password' => 'Spravne-heslo1'])->json('state');
        $this->asUser()->postJson(route('webauthn.register'), ['state' => $state, 'clientDataJSON' => 'eA==', 'attestationObject' => 'eA=='])
            ->assertStatus(422)->assertExactJson(['error' => 'Registraci se nepodařilo ověřit: bad attestation']);
    }

    // ── správa ───────────────────────────────────────────────────────────────

    public function test_seznam_a_smazani_jen_vlastnich(): void
    {
        $mine = $this->cred('m1');
        $other = $this->cred('o1', 0, (int) DB::table('users')->where('id', '<>', $this->userId)->value('id'));

        $res = $this->asUser()->get(route('webauthn.index'))->assertOk()->assertViewIs('webauthn.index');
        $this->assertSame([$mine], $res->viewData('credentials')->pluck('id')->all());

        $this->asUser()->deleteJson(route('webauthn.credential.destroy', $other))->assertOk()->assertExactJson(['ok' => false]);
        $this->assertTrue(DB::table('webauthn_credentials')->where('id', $other)->exists(), 'cizí klíč nesmazat');
        $this->asUser()->deleteJson(route('webauthn.credential.destroy', $mine))->assertOk()->assertExactJson(['ok' => true]);
        $this->assertFalse(DB::table('webauthn_credentials')->where('id', $mine)->exists());
        $a = DB::table('audit_logs')->where('auditable_type', 'webauthn_credentials')->where('auditable_id', $mine)->where('action', 'deleted')->orderByDesc('id')->first();
        $this->assertSame(['user_id' => $this->userId], json_decode($a->old_values, true));
    }

    // ── přihlášení ───────────────────────────────────────────────────────────

    public function test_volby_prihlaseni_vzdy_bez_loginu(): void
    {
        // dřív: /webauthn/check a cílená výzva prozradily, zda login existuje a má passkey
        $this->postJson('/webauthn/check', ['login' => $this->login])->assertNotFound();
        $this->cred('k1');
        $this->wa->shouldReceive('getGetArgs')->times(3)->with([], 60, true, true, true, true, true, true)->andReturn((object) ['publicKey' => ['a' => 2]]);
        $known   = $this->postJson(route('webauthn.login.options'), ['login' => $this->login])->assertOk()->json();
        $unknown = $this->postJson(route('webauthn.login.options'), ['login' => 'neexistuje_' . uniqid()])->assertOk()->json();
        $none    = $this->postJson(route('webauthn.login.options'))->assertOk()->json();
        $this->assertSame([['a' => 2], ['a' => 2], ['a' => 2]], [$known['publicKey'], $unknown['publicKey'], $none['publicKey']]);
    }

    public function test_pridani_passkey_vyzaduje_heslo(): void
    {
        $this->wa->shouldReceive('getCreateArgs')->andReturn((object) ['publicKey' => []]);
        $this->asUser()->postJson(route('webauthn.register.options'), ['password' => 'spatne'])->assertStatus(422)->assertExactJson(['error' => 'Nesprávné heslo.']);
        $this->asUser()->postJson(route('webauthn.register.options'))->assertStatus(422);
        $this->asUser()->postJson(route('webauthn.register.options'), ['password' => 'Spravne-heslo1'])->assertOk();
    }

    public function test_vyzva_z_prihlaseni_neposlouzi_k_registraci(): void
    {
        $this->wa->shouldReceive('getGetArgs')->andReturn((object) ['publicKey' => []]);
        $this->wa->shouldReceive('processCreate')->never();
        $loginState = $this->postJson(route('webauthn.login.options'))->json('state');
        $this->asUser()->postJson(route('webauthn.register'), ['state' => $loginState, 'clientDataJSON' => 'eA==', 'attestationObject' => 'eA=='])
            ->assertStatus(422)->assertExactJson(['error' => 'Vypršela platnost výzvy. Zkuste to znovu.']);
        $this->assertSame(0, DB::table('webauthn_credentials')->where('user_id', $this->userId)->count());
    }

    private function loginPayload(string $state, string $rawId, ?string $context = null): array
    {
        return ['id' => base64_encode($rawId), 'state' => $state, 'clientDataJSON' => base64_encode('CDJ'),
            'authenticatorData' => base64_encode('AD'), 'signature' => base64_encode('SIG'), 'context' => $context];
    }

    private function state(): string
    {
        $this->wa->shouldReceive('getGetArgs')->andReturn((object) ['publicKey' => []]);
        return $this->postJson(route('webauthn.login.options'))->json('state');
    }

    public function test_prihlaseni(): void
    {
        $cid = $this->cred('k1', 5);
        $this->wa->shouldReceive('processGet')->once()
            ->with('CDJ', 'AD', 'SIG', 'PUBKEY', Mockery::on(fn ($b) => $b->getBinaryString() === 'challenge-bytes'), 5, true, true)->andReturnTrue();
        $this->wa->shouldReceive('getSignatureCounter')->andReturn(6);

        $logs = DB::table('login_logs')->where('user_id', $this->userId)->count();
        $this->postJson(route('webauthn.login'), $this->loginPayload($this->state(), 'k1', 'field'))
            ->assertOk()->assertExactJson(['ok' => true, 'redirect' => route('field.search'), 'login' => $this->login]);
        $this->assertAuthenticatedAs(User::find($this->userId));
        $this->assertTrue(session('mfa_passed'));
        $row = DB::table('webauthn_credentials')->where('id', $cid)->first();
        $this->assertSame(6, (int) $row->sign_count);
        $this->assertNotNull($row->last_used_at);
        $this->assertSame($logs + 1, DB::table('login_logs')->where('user_id', $this->userId)->count());
    }

    public function test_prihlaseni_odmitnuti(): void
    {
        $this->postJson(route('webauthn.login'), $this->loginPayload('neplatny', 'k1'))->assertStatus(422)->assertExactJson(['error' => 'Vypršela platnost výzvy. Zkuste to znovu.']);
        $this->postJson(route('webauthn.login'), $this->loginPayload($this->state(), 'neznamy'))->assertStatus(422)->assertExactJson(['error' => 'Neznámé biometrické zařízení.']);

        $this->cred('k1', 10);
        $this->wa->shouldReceive('processGet')->andReturnTrue();
        $this->wa->shouldReceive('getSignatureCounter')->andReturn(10);
        $this->postJson(route('webauthn.login'), $this->loginPayload($this->state(), 'k1'))
            ->assertStatus(422)->assertExactJson(['error' => 'Bezpečnostní kontrola selhala (možná klonovaný klíč).']);
        $this->assertGuest();

        DB::table('webauthn_credentials')->where('credential_id', base64_encode('k1'))->update(['sign_count' => 0]);
        DB::table('members')->where('id', $this->memberId)->update(['locked' => 1]);
        $this->postJson(route('webauthn.login'), $this->loginPayload($this->state(), 'k1'))->assertStatus(422)->assertExactJson(['error' => 'Účet je zablokován.']);
        $this->assertGuest();

        DB::table('members')->where('id', $this->memberId)->update(['locked' => 0]);
        $this->postJson(route('webauthn.login'), $this->loginPayload($this->state(), 'k1'))->assertOk()->assertJson(['redirect' => route('dashboard')]);
    }

    public function test_prihlaseni_selhani_overeni(): void
    {
        $this->cred('k1');
        $this->wa->shouldReceive('processGet')->andThrow(new \RuntimeException('invalid signature'));
        $this->postJson(route('webauthn.login'), $this->loginPayload($this->state(), 'k1'))
            ->assertStatus(422)->assertExactJson(['error' => 'Ověření se nezdařilo: invalid signature']);
        $this->assertGuest();
    }
}
