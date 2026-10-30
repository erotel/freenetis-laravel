<?php

namespace Tests\Feature\Auth;

use App\Domains\Acl\AclService;
use App\Domains\Members\Support\PasswordStrength;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Síla hesla (security_password_level) se dřív hlídala jen JavaScriptem
 * v prohlížeči (Kohana jquery.validate.password), server bral cokoli dost
 * dlouhého. Teď ji vynucuje StrongPassword na všech cestách, kde se heslo
 * nastavuje: registrace, nový člen, nový uživatel, změna, reset.
 */
class PasswordPolicyTest extends DatabaseTestCase
{
    private const WEAK = 'Heslo je příliš slabé (požadováno: silné). Použijte malá i velká písmena a číslice, případně speciální znak.';

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('security_password_level', 4);
        Setting::set('security_password_length', 8);
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
    }

    private function asAdmin()
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnTrue();
        $acl->shouldReceive('canManageUser')->andReturnTrue();   // ochrana vyšších účtů: tests/Feature/Security/UserPrivilegeEscalationTest
        $acl->shouldReceive('flushUserCache')->andReturnNull();
        return $this->actingAs(User::find($this->adminId));
    }

    private function pwd(string $p): array
    {
        return ['password' => $p, 'password_confirmation' => $p];
    }

    // ── výpočet (shodný s Kohana JS) ─────────────────────────────────────────

    public function test_hodnoceni_jako_kohana(): void
    {
        $cases = [
            'aaaaaaaa' => 1, '11111111' => 1,
            'abcdefgh' => 2, 'ABCDEFGH' => 2, '12345678' => 2, 'Abcdefgh' => 2,   // velké na začátku se nepočítá
            'abcDefgh' => 3, 'abcdefg1' => 3, 'ABCDEFG1' => 3, 'Abcdefg1' => 3,
            'abcDefg1' => 4, 'abcdef12' => 4, 'ABCDEF12' => 4, 'abcdefg!' => 4, 'heslo 12' => 4, 'příliš123' => 4,
        ];
        foreach ($cases as $password => $level) {
            $this->assertSame($level, PasswordStrength::rate($password), $password);
        }
        $this->assertTrue(PasswordStrength::containsUsername('xxJanNovakxx1', 'jannovak'));
        $this->assertFalse(PasswordStrength::containsUsername('abcDefg1', ''));
    }

    public function test_uroven_z_nastaveni(): void
    {
        Setting::set('security_password_level', 2);
        $this->asAdmin()->put(route('users.password.update', $this->adminId), $this->pwd('abcdefgh'))->assertSessionHasNoErrors();
        Setting::set('security_password_level', 3);
        $this->asAdmin()->put(route('users.password.update', $this->adminId), $this->pwd('abcdefgh'))
            ->assertSessionHasErrors(['password' => 'Heslo je příliš slabé (požadováno: dobré). Použijte malá i velká písmena a číslice, případně speciální znak.']);
    }

    // ── všechny cesty ────────────────────────────────────────────────────────

    public function test_zmena_hesla(): void
    {
        $this->asAdmin()->put(route('users.password.update', $this->adminId), $this->pwd('abcdefgh'))->assertSessionHasErrors(['password' => self::WEAK]);
        $login = User::find($this->adminId)->login;
        $this->asAdmin()->put(route('users.password.update', $this->adminId), $this->pwd('Xx1!' . $login))
            ->assertSessionHasErrors(['password' => 'Heslo nesmí obsahovat přihlašovací jméno.']);
        $this->asAdmin()->put(route('users.password.update', $this->adminId), $this->pwd(\Tests\Support\Fake::secret('silneheslo')))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(\Tests\Support\Fake::secret('silneheslo'), DB::table('users')->where('id', $this->adminId)->value('password')));
    }

    public function test_reset_hesla(): void
    {
        Setting::set('forgotten_password', 1);
        $token = str_repeat('b', 40);
        DB::table('users')->where('id', $this->adminId)->update(['password_request' => hash('sha256', $token), 'password_request_expires_at' => now()->addMinutes(10)]);
        $login = User::find($this->adminId)->login;

        $this->post(route('forgotten-password.update'), ['token' => $token] + $this->pwd('abcdefgh'))->assertSessionHasErrors(['password' => self::WEAK]);
        $this->post(route('forgotten-password.update'), ['token' => $token] + $this->pwd('Xx1!' . $login))
            ->assertSessionHasErrors(['password' => 'Heslo nesmí obsahovat přihlašovací jméno.']);
        $this->post(route('forgotten-password.update'), ['token' => $token] + $this->pwd(\Tests\Support\Fake::secret('silneheslo')))->assertSessionHasNoErrors()->assertRedirect(route('login'));
    }

    public function test_registrace_novy_clen_a_uzivatel(): void
    {
        Setting::set('self_registration', 1);
        $this->post(route('registration.store'), ['login' => 'charpwd'] + $this->pwd('abcdefgh'))->assertSessionHasErrors(['password' => self::WEAK]);
        $this->post(route('registration.store'), ['login' => 'charpwd'] + $this->pwd('Xx1!charpwd'))
            ->assertSessionHasErrors(['password' => 'Heslo nesmí obsahovat přihlašovací jméno.']);
        $this->post(route('registration.store'), ['login' => 'charpwd'] + $this->pwd(\Tests\Support\Fake::secret('silneheslo')))->assertSessionDoesntHaveErrors('password');

        $this->asAdmin()->post(route('members.store'), ['login' => 'charpwd2'] + $this->pwd('abcdefgh'))->assertSessionHasErrors(['password' => self::WEAK]);
        $this->asAdmin()->post(route('members.store'), ['login' => 'charpwd2'] + $this->pwd('Abc1'))->assertSessionHasErrors('password');   // délka z nastavení (dřív natvrdo 6)
        $this->asAdmin()->post(route('members.store'), ['login' => 'charpwd2'] + $this->pwd(\Tests\Support\Fake::secret('silneheslo')))->assertSessionDoesntHaveErrors('password');

        $this->asAdmin()->post(route('users.store'), ['login' => 'charpwd3'] + $this->pwd('abcdefgh'))->assertSessionHasErrors(['password' => self::WEAK]);
        $this->asAdmin()->post(route('users.store'), ['login' => 'charpwd3'] + $this->pwd(\Tests\Support\Fake::secret('silneheslo')))->assertSessionDoesntHaveErrors('password');
        // úprava uživatele heslo nemění vůbec (jen změna hesla) — pole se ignoruje
        $before = DB::table('users')->where('id', $this->adminId)->value('password');
        $this->asAdmin()->put(route('users.update', $this->adminId), $this->pwd('abcdefgh'))->assertSessionDoesntHaveErrors('password');
        $this->assertSame($before, DB::table('users')->where('id', $this->adminId)->value('password'));
    }

    public function test_formulare_ukazuji_pozadavky(): void
    {
        $hint = 'Alespoň 8 znaků; malá i velká písmena a číslice, nebo písmena a aspoň 2 číslice, nebo speciální znak. Heslo nesmí obsahovat přihlašovací jméno.';
        $this->asAdmin()->get(route('users.password', $this->adminId))->assertOk()->assertSee($hint);
        $this->asAdmin()->get(route('members.create'))->assertOk()->assertSee($hint);
        $this->asAdmin()->get(route('users.create', ['member_id' => (int) DB::table('users')->where('id', $this->adminId)->value('member_id')]))->assertOk()->assertSee($hint);

        Setting::set('forgotten_password', 1);
        $token = str_repeat('c', 40);
        DB::table('users')->where('id', $this->adminId)->update(['password_request' => hash('sha256', $token), 'password_request_expires_at' => now()->addMinutes(10)]);
        $this->app['auth']->forgetGuards();
        auth()->logout();
        $this->get(route('forgotten-password.reset', ['request' => $token]))->assertOk()->assertSee($hint);
    }
}
