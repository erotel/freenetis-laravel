<?php

namespace Tests\Feature\Auth;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Regrese: po resetu / změně hesla zůstávala ostatní přihlášení platná.
 * Reset mazal jen DB tabulku `sessions`, ale produkce má SESSION_DRIVER=file
 * → no-op. AuthenticateSession (web) teď odhlásí každou session, jejíž heslo
 * se mezitím změnilo — nezávisle na úložišti sessions.
 */
class SessionInvalidationTest extends DatabaseTestCase
{
    private int $userId;
    private string $login;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('forgotten_password', 1);
        $memberId = (int) DB::table('members')->where('locked', 0)->where('type', 2)->where('leaving_date', '9999-12-31')->value('id');
        $this->login  = 'charsess_' . uniqid();
        $this->userId = (int) DB::table('users')->insertGetId(['member_id' => $memberId, 'login' => $this->login, 'password' => Hash::make(\Tests\Support\Fake::secret('puvodniheslo')),
            'type' => 1, 'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'S', 'comment' => '']);
    }

    private function loggedIn(): void
    {
        $this->post('/login', ['login' => $this->login, 'password' => \Tests\Support\Fake::secret('puvodniheslo')]);
        $this->assertAuthenticated();
        $this->get(route('dashboard'))->assertRedirect();     // session drží hash hesla
        $this->assertAuthenticated();
    }

    /** Další request = nový proces: guard nesmí držet uživatele z minula. */
    private function nextRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_zmena_hesla_odhlasi_existujici_session(): void
    {
        $this->loggedIn();
        DB::table('users')->where('id', $this->userId)->update(['password' => Hash::make(\Tests\Support\Fake::secret('noveheslo'))]);
        $this->nextRequest();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_reset_hesla_odhlasi_existujici_session(): void
    {
        $this->loggedIn();
        $token = 'T' . str_repeat('a', 39);
        DB::table('users')->where('id', $this->userId)->update(['password_request' => hash('sha256', $token), 'password_request_expires_at' => now()->addMinutes(10)]);

        // reset z jiného zařízení (formulář je jen pro nepřihlášené)
        $this->assertTrue(app(\App\Domains\Members\Actions\ResetPasswordAction::class)->handle($token, \Tests\Support\Fake::secret('noveheslo')));
        $this->nextRequest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_beze_zmeny_hesla_session_plati(): void
    {
        $this->loggedIn();
        $this->nextRequest();
        $this->get(route('dashboard'))->assertRedirect(route('members.show', DB::table('users')->where('id', $this->userId)->value('member_id')));
        $this->assertAuthenticated();
    }
}
