<?php

namespace Tests\Feature\Auth;

use App\Models\Setting;
use App\Models\User;
use App\Services\LoginLockService;
use App\Services\MfaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Regrese (audit 2026-08-14): /field/login byla druhá přihlašovací cesta, která
 * obcházela MFA gate i trvalý zámek účtu (volala přímo Auth::attempt). Po
 * sjednocení do LoginService musí field login respektovat stejné brány jako
 * /login. Viz [[project_security_hardening]] (audit findings).
 */
class FieldLoginParityTest extends DatabaseTestCase
{
    private int $userId;
    private string $login;
    private string $pass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pass  = 'Heslo-' . uniqid();
        $this->login = 'fieldtest_' . uniqid();

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
            'name'                => 'Field', 'surname' => 'Test', 'comment' => '',
        ]);
    }

    public function test_field_login_bez_mfa_se_prihlasi(): void
    {
        $r = $this->post('/field/login', ['login' => $this->login, 'password' => $this->pass]);
        $r->assertRedirect(route('field.search'));
        $this->assertAuthenticatedAs(User::find($this->userId));
    }

    public function test_field_login_s_mfa_nesmi_obejit_druhy_faktor(): void
    {
        $svc = app(MfaService::class);
        $svc->enable($this->userId, $svc->generateSecret());

        $r = $this->post('/field/login', ['login' => $this->login, 'password' => $this->pass]);

        // MFA gate platí i pro field — jen challenge, žádná plná session.
        $r->assertRedirect(route('mfa.challenge'));
        $r->assertSessionHas('mfa.pending_user_id', $this->userId);
        $r->assertSessionHas('url.intended', route('field.search'));
        $this->assertGuest();
    }

    public function test_field_login_respektuje_zamek_uctu(): void
    {
        Setting::set('login_lockout_threshold', 3);
        Setting::set('login_lockout_minutes', 15);

        // Selhání přes field musí plnit počítadlo a zamknout účet (dřív ne).
        for ($i = 0; $i < 3; $i++) {
            $this->post('/field/login', ['login' => $this->login, 'password' => 'spatne']);
        }

        $this->assertTrue(app(LoginLockService::class)->isLocked($this->userId));

        // I se správným heslem se během zámku přes field nepřihlásí.
        $r = $this->post('/field/login', ['login' => $this->login, 'password' => $this->pass]);
        $r->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_zamek_je_sdileny_mezi_login_a_field(): void
    {
        Setting::set('login_lockout_threshold', 3);
        Setting::set('login_lockout_minutes', 15);

        // Mix selhání napříč oběma endpointy se sčítá na jeden účet.
        $this->post('/login', ['login' => $this->login, 'password' => 'spatne']);
        $this->post('/field/login', ['login' => $this->login, 'password' => 'spatne']);
        $this->post('/login', ['login' => $this->login, 'password' => 'spatne']); // 3. → zámek

        $this->assertTrue(app(LoginLockService::class)->isLocked($this->userId));
    }
}
