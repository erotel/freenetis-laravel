<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\MfaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\DatabaseTestCase;

/**
 * Dvoufázové přihlášení (MFA) — fáze A: partial-auth flow (heslo → 2. faktor),
 * TOTP i záložní kód, uživatel bez MFA se přihlásí rovnou.
 */
class MfaTest extends DatabaseTestCase
{
    private int $userId;
    private string $login;
    private string $pass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pass  = 'Heslo-' . uniqid();
        $this->login = 'mfatest_' . uniqid();

        // Existující odemčený člen (kvůli members.address_point_id FK nevytváříme nový).
        $memberId = (int) DB::table('members')
            ->where('locked', 0)->where('type', 2)->where('leaving_date', '9999-12-31')
            ->value('id');

        $this->userId = (int) DB::table('users')->insertGetId([
            'member_id'            => $memberId,
            'login'               => $this->login,
            'password'            => Hash::make($this->pass),
            'type'                => 1,
            'application_password'=> 'xxxxxxxx',
            'settings'            => '',
            'name'                => 'MFA',
            'surname'             => 'Test',
            'comment'             => '',
        ]);
    }

    private function enableMfa(): string
    {
        $svc = app(MfaService::class);
        $secret = $svc->generateSecret();
        $svc->enable($this->userId, $secret);
        return $secret;
    }

    public function test_bez_mfa_se_prihlasi_rovnou(): void
    {
        $r = $this->post('/login', ['login' => $this->login, 'password' => $this->pass]);
        $r->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs(User::find($this->userId));
    }

    public function test_s_mfa_presmeruje_na_challenge_a_neprihlasi(): void
    {
        $this->enableMfa();
        $r = $this->post('/login', ['login' => $this->login, 'password' => $this->pass]);
        $r->assertRedirect(route('mfa.challenge'));
        $r->assertSessionHas('mfa.pending_user_id', $this->userId);
        $this->assertGuest();
    }

    public function test_spravny_totp_dokonci_login(): void
    {
        $secret = $this->enableMfa();
        $this->post('/login', ['login' => $this->login, 'password' => $this->pass]);

        $otp = (new Google2FA())->getCurrentOtp($secret);
        $r = $this->post(route('mfa.challenge.verify'), ['code' => $otp, 'mode' => 'totp']);

        $r->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs(User::find($this->userId));
    }

    public function test_spatny_totp_neprihlasi(): void
    {
        $this->enableMfa();
        $this->post('/login', ['login' => $this->login, 'password' => $this->pass]);

        $r = $this->post(route('mfa.challenge.verify'), ['code' => '000000', 'mode' => 'totp']);

        $r->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_zalozni_kod_prihlasi_a_spotrebuje_se(): void
    {
        $this->enableMfa();
        $codes = app(MfaService::class)->generateRecoveryCodes($this->userId);

        $this->post('/login', ['login' => $this->login, 'password' => $this->pass]);
        $r = $this->post(route('mfa.challenge.verify'), ['code' => $codes[0], 'mode' => 'recovery']);

        $r->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs(User::find($this->userId));

        // Stejný kód už podruhé neprojde.
        $this->assertFalse(app(MfaService::class)->verifyAndConsumeRecovery($this->userId, $codes[0]));
    }

    public function test_admin_reset_vypne_mfa(): void
    {
        $this->enableMfa();
        $this->assertTrue(app(MfaService::class)->isEnabled($this->userId));

        app(MfaService::class)->disable($this->userId);

        $this->assertFalse(app(MfaService::class)->isEnabled($this->userId));
        $this->assertSame(0, DB::table('mfa_recovery_codes')->where('user_id', $this->userId)->count());
    }
}
