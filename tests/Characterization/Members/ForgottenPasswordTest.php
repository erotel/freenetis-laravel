<?php

namespace Tests\Characterization\Members;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace zapomenutého hesla (ForgottenPasswordController) před
 * přesunem do domény: dohledání účtu (login → jediný aktivní účet s e-mailem
 * → VS hlavního uživatele), vždy stejná odpověď (bez enumerace), token jen
 * jako SHA-256 s platností 15 min, e-mail do fronty, reset (jednorázový,
 * expirace, délka hesla), audit, vypnutá funkce = 404.
 */
class ForgottenPasswordTest extends DatabaseTestCase
{
    private const GENERIC = 'Pokud existuje účet odpovídající zadanému údaji, zaslali jsme na příslušný e-mail odkaz pro reset hesla. Pokud zpráva nedorazí do 20 minut, kontaktujte prosím podporu.';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('forgotten_password', 1);
        Setting::set('security_password_length', 8);
        Setting::set('title', 'Char FN');
        Setting::set('email_default_email', 'noreply@example.test');
    }

    /** @return array{member:int, user:int, login:string, email:string, vs:string} */
    private function account(bool $locked = false, ?string $email = 'auto'): array
    {
        $m = (int) DB::table('members')->insertGetId(['name' => 'Char FP', 'type' => 2, 'registration' => 1, 'locked' => $locked ? 1 : 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $login = 'charfp_' . uniqid();
        $u = (int) DB::table('users')->insertGetId(['member_id' => $m, 'login' => $login, 'password' => Hash::make(\Tests\Support\Fake::secret('stareheslo')), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'F', 'comment' => '']);
        $email = $email === 'auto' ? $login . '@example.invalid' : $email;
        if ($email !== null) {
            $c = (int) DB::table('contacts')->insertGetId(['type' => 20, 'value' => $email]);
            DB::table('users_contacts')->insert(['user_id' => $u, 'contact_id' => $c]);
        }
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $m, 'account_attribute_id' => 221100, 'balance' => 0, 'name' => 'c']);
        do {
            $vs = (string) random_int(80000000, 89999999);
        } while (DB::table('variable_symbols')->where('variable_symbol', $vs)->exists());
        DB::table('variable_symbols')->insert(['account_id' => $acc, 'variable_symbol' => $vs]);
        return ['member' => $m, 'user' => $u, 'login' => $login, 'email' => (string) $email, 'vs' => $vs];
    }

    private function request(string $login)
    {
        return $this->post(route('forgotten-password.store'), ['login' => $login]);
    }

    private function mail(string $to): ?object
    {
        return DB::table('email_queues')->where('to', $to)->orderByDesc('id')->first();
    }

    /** Raw token z odkazu v e-mailu. */
    private function token(string $to): string
    {
        preg_match('~\?request=([A-Za-z0-9]+)~', $this->mail($to)->body, $m);
        return $m[1];
    }

    private function userRow(int $id): object
    {
        return DB::table('users')->where('id', $id)->first();
    }

    // ── žádost ───────────────────────────────────────────────────────────────

    public function test_zadost_podle_loginu_e_mail_a_token(): void
    {
        $this->get(route('forgotten-password'))->assertOk()->assertViewIs('auth.forgotten_password');
        $x = $this->account();

        $this->request('  ' . $x['login'] . '  ')->assertRedirect(route('forgotten-password'))->assertSessionHas('success', self::GENERIC);
        $mail = $this->mail($x['email']);
        $this->assertSame(['noreply@example.test', 'Char FN :: Reset hesla', 0], [$mail->from, $mail->subject, (int) $mail->state]);
        $token = $this->token($x['email']);
        $this->assertSame(40, strlen($token));
        $this->assertStringContainsString('platnost 15 minut', $mail->body);
        $this->assertStringContainsString(htmlspecialchars(route('forgotten-password.reset') . '?request=' . $token), $mail->body);

        $row = $this->userRow($x['user']);
        $this->assertSame(hash('sha256', $token), $row->password_request, 'v DB jen hash');
        $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, strtotime($row->password_request_expires_at), 5);
        $a = DB::table('audit_logs')->where('auditable_type', 'users')->where('auditable_id', $x['user'])->orderByDesc('id')->first();
        $this->assertSame(['password_reset_requested' => true], json_decode($a->new_values, true));
    }

    public function test_zadost_podle_e_mailu_a_vs(): void
    {
        $x = $this->account();
        $this->request($x['email'])->assertSessionHas('success', self::GENERIC);
        $this->assertNotNull($this->mail($x['email']), 'podle e-mailu');

        $y = $this->account();
        $this->request($y['vs'])->assertSessionHas('success', self::GENERIC);
        $this->assertNotNull($this->mail($y['email']), 'podle VS');

        // sdílený e-mail: jen když zbude právě jeden aktivní účet
        $shared = 'shared_' . uniqid() . '@example.invalid';
        $a = $this->account(false, $shared);
        $b = $this->account(true, $shared);
        $this->request($shared);
        $this->assertNotNull($this->userRow($a['user'])->password_request, 'zamčený se nepočítá');
        $this->assertNull($this->userRow($b['user'])->password_request);

        $shared2 = 'shared2_' . uniqid() . '@example.invalid';
        $c = $this->account(false, $shared2);
        $d = $this->account(false, $shared2);
        $this->request($shared2)->assertSessionHas('success', self::GENERIC);
        $this->assertNull($this->userRow($c['user'])->password_request, 'víc aktivních → nic');
        $this->assertNull($this->userRow($d['user'])->password_request);
    }

    public function test_bez_uctu_nebo_e_mailu_stejna_odpoved(): void
    {
        $before = DB::table('email_queues')->max('id');
        $this->request('neexistuje_' . uniqid())->assertRedirect(route('forgotten-password'))->assertSessionHas('success', self::GENERIC);
        $x = $this->account(false, null);
        $this->request($x['login'])->assertSessionHas('success', self::GENERIC);
        $this->assertNull($this->userRow($x['user'])->password_request, 'bez e-mailu se token nezakládá');
        $this->assertSame($before, DB::table('email_queues')->max('id'));
        $this->request('')->assertSessionHasErrors('login');
    }

    // ── reset ────────────────────────────────────────────────────────────────

    public function test_reset_hesla(): void
    {
        $x = $this->account();
        $this->request($x['login']);
        $token = $this->token($x['email']);

        $this->get(route('forgotten-password.reset', ['request' => $token]))->assertOk()->assertViewIs('auth.reset_password')->assertViewHas('token', $token);
        $this->get(route('forgotten-password.reset', ['request' => 'spatny']))->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Odkaz pro reset hesla je neplatný nebo vypršel.');
        $this->get(route('forgotten-password.reset'))->assertRedirect(route('login'));

        $this->post(route('forgotten-password.update'), ['token' => $token, 'password' => 'kratke', 'password_confirmation' => 'kratke'])->assertSessionHasErrors('password');
        $this->post(route('forgotten-password.update'), ['token' => $token, 'password' => \Tests\Support\Fake::secret('noveheslo'), 'password_confirmation' => 'jine'])->assertSessionHasErrors('password');

        $this->post(route('forgotten-password.update'), ['token' => $token, 'password' => \Tests\Support\Fake::secret('noveheslo'), 'password_confirmation' => \Tests\Support\Fake::secret('noveheslo')])
            ->assertRedirect(route('login'))->assertSessionHas('success', 'Heslo bylo úspěšně změněno. Nyní se můžete přihlásit.');
        $row = $this->userRow($x['user']);
        $this->assertTrue(Hash::check(\Tests\Support\Fake::secret('noveheslo'), $row->password));
        $this->assertSame([null, null], [$row->password_request, $row->password_request_expires_at]);
        $a = DB::table('audit_logs')->where('auditable_type', 'users')->where('auditable_id', $x['user'])->orderByDesc('id')->first();
        $this->assertSame(['password' => '***', 'password_reset' => true], json_decode($a->new_values, true));

        $this->post(route('forgotten-password.update'), ['token' => $token, 'password' => \Tests\Support\Fake::secret('jineheslo'), 'password_confirmation' => \Tests\Support\Fake::secret('jineheslo')])
            ->assertRedirect(route('login'))->assertSessionHas('error', 'Odkaz pro reset hesla je neplatný nebo vypršel.');
        $this->assertTrue(Hash::check(\Tests\Support\Fake::secret('noveheslo'), $this->userRow($x['user'])->password), 'token jednorázový');
    }

    public function test_expirace_a_legacy_token(): void
    {
        $x = $this->account();
        $this->request($x['login']);
        $token = $this->token($x['email']);

        DB::table('users')->where('id', $x['user'])->update(['password_request_expires_at' => now()->subMinute()]);
        $this->get(route('forgotten-password.reset', ['request' => $token]))->assertRedirect(route('login'));
        DB::table('users')->where('id', $x['user'])->update(['password_request_expires_at' => null]);
        $this->get(route('forgotten-password.reset', ['request' => $token]))->assertRedirect(route('login'));
    }

    public function test_vypnuto(): void
    {
        Setting::set('forgotten_password', 0);
        $this->get(route('forgotten-password'))->assertNotFound();
        $this->post(route('forgotten-password.store'), ['login' => 'x'])->assertNotFound();
        $this->get(route('forgotten-password.reset', ['request' => 'x']))->assertNotFound();
        $this->post(route('forgotten-password.update'), ['token' => 'x'])->assertNotFound();
    }
}
