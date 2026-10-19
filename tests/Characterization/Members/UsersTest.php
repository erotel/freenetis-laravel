<?php

namespace Tests\Characterization\Members;

use App\Models\User;
use App\Services\AclService;
use App\Services\MfaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace uživatelů (UserController) před přesunem do domény Members:
 * seznam (hledání login/jméno/kontakt, víc slov, filtr člena, řazení),
 * karta (skupiny, zařízení, MFA, zámek), odemčení a reset MFA (audit),
 * založení a úprava s jemnými právy (login, komentář, člen), smazání
 * (ne sebe, ne se zařízeními, ne jediného uživatele člena), změna hesla
 * (sám s aktuálním heslem / admin bez něj), tmavý režim, ACL.
 */
class UsersTest extends DatabaseTestCase
{
    private int $adminId;
    private int $memberId;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->tag = 'us' . substr(md5(uniqid('', true)), 0, 8);
        $this->memberId = (int) DB::table('members')->insertGetId(['name' => 'Char Users', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $this->acl();
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => (int) $u === $this->adminId && !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function as(?int $id = null)
    {
        return $this->actingAs(User::find($id ?? $this->adminId));
    }

    private function user(string $suffix, int $type = 1, ?string $password = null): int
    {
        $password ??= \Tests\Support\Fake::secret('puvodniheslo');
        return (int) DB::table('users')->insertGetId(['member_id' => $this->memberId, 'login' => "{$this->tag}{$suffix}", 'password' => Hash::make($password),
            'type' => $type, 'application_password' => 'x', 'settings' => '', 'name' => 'Jan', 'surname' => "Novak{$this->tag}", 'comment' => '']);
    }

    private function pwd(string $p): array
    {
        return ['password' => $p, 'password_confirmation' => $p];
    }

    // ── seznam, karta ────────────────────────────────────────────────────────

    public function test_seznam_a_karta(): void
    {
        $a = $this->user('a');
        $b = $this->user('b', 2);
        $c = (int) DB::table('contacts')->insertGetId(['type' => 20, 'value' => "{$this->tag}@example.invalid"]);
        DB::table('users_contacts')->insert(['user_id' => $b, 'contact_id' => $c]);

        $ids = fn (array $q) => collect($this->as()->get(route('users.index', $q))->assertOk()->viewData('users')->items())->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertSame([$a, $b], $ids(['search' => $this->tag]));
        $this->assertSame([$b, $a], $ids(['search' => $this->tag, 'sort' => 'id', 'dir' => 'desc']));
        $this->assertSame([$b], $ids(['search' => "{$this->tag}@example"]), 'kontakt');
        $this->assertSame([$a, $b], $ids(['search' => "Novak{$this->tag} Jan"]), 'víc slov');
        $this->assertSame([$a, $b], $ids(['member_id' => $this->memberId]));
        $res = $this->as()->get(route('users.index', ['sort' => 'heslo', 'record_per_page' => 7]));
        $this->assertSame(['id', 'asc', 50], [$res->viewData('sort'), $res->viewData('dir'), $res->viewData('perPage')]);
        $this->as()->get(route('users.by_member', $this->memberId))->assertRedirect(route('users.index', ['member_id' => $this->memberId]));

        $group = (int) DB::table('aro_groups')->orderBy('id')->value('id');
        DB::table('groups_aro_map')->insert(['group_id' => $group, 'aro_id' => $a]);
        $show = $this->as()->get(route('users.show', $a))->assertOk()->assertViewIs('users.show');
        $this->assertSame([$group], $show->viewData('aroGroups')->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame([false, 0], [$show->viewData('mfaEnabled'), $show->viewData('loginLock')['failed_count']]);
        $this->assertTrue($show->viewData('canChangePassword') && $show->viewData('canResetMfa'));
        $this->as()->get(route('users.show', 999999999))->assertNotFound();
    }

    public function test_odemceni_a_reset_mfa(): void
    {
        $a = $this->user('a');
        DB::table('user_login_locks')->insert(['user_id' => $a, 'failed_count' => 9, 'locked_until' => now()->addHour()]);
        $this->as()->from(route('users.show', $a))->post(route('users.login_unlock', $a))
            ->assertRedirect(route('users.show', $a))->assertSessionHas('success', 'Účet byl odemčen.');
        $this->assertSame(0, (int) DB::table('user_login_locks')->where('user_id', $a)->value('failed_count'));
        $audit = DB::table('audit_logs')->where('auditable_type', 'users')->where('auditable_id', $a)->orderByDesc('id')->first();
        $this->assertSame(['login_unlock', ['by_user_id' => $this->adminId]], [$audit->action, json_decode($audit->new_values, true)]);

        $mfa = app(MfaService::class);
        $mfa->enable($a, $mfa->generateSecret());
        $this->as()->from(route('users.show', $a))->post(route('users.mfa.reset', $a))->assertSessionHas('success', 'Dvoufázové přihlášení uživatele bylo resetováno.');
        $this->assertFalse($mfa->isEnabled($a));
        $this->assertSame('mfa_reset', DB::table('audit_logs')->where('auditable_type', 'users')->where('auditable_id', $a)->orderByDesc('id')->value('action'));
        $this->as()->post(route('users.mfa.reset', 999999999))->assertNotFound();
    }

    // ── založení, úprava ─────────────────────────────────────────────────────

    public function test_zalozeni_a_uprava_s_jemnymi_pravy(): void
    {
        $this->as()->get(route('users.create', ['member_id' => $this->memberId]))->assertOk()->assertViewHas('canEditLogin', true);
        $this->as()->post(route('users.store'), ['member_id' => $this->memberId, 'login' => "{$this->tag}n", 'surname' => 'Novy', 'type' => 2] + $this->pwd(\Tests\Support\Fake::secret('silneheslo')))
            ->assertRedirect(route('members.show', $this->memberId))->assertSessionHas('success', 'Uživatel byl úspěšně přidán.');
        $new = DB::table('users')->where('login', "{$this->tag}n")->first();
        $this->assertTrue(Hash::check(\Tests\Support\Fake::secret('silneheslo'), $new->password));
        $this->assertSame([2, ''], [(int) $new->type, $new->settings]);
        $this->assertNotSame('', \Illuminate\Support\Facades\Crypt::decryptString($new->application_password), 'šifrované aplikační heslo');

        $this->acl(['new_all/Users_Controller/login']);
        $this->as()->post(route('users.store'), ['member_id' => $this->memberId, 'login' => "{$this->tag}x", 'surname' => 'Bezloginu', 'type' => 2] + $this->pwd(\Tests\Support\Fake::secret('silneheslo')));
        $this->assertFalse(DB::table('users')->where('login', "{$this->tag}x")->exists(), 'login bez práva se zahodí');
        $this->assertTrue(DB::table('users')->where('surname', 'Bezloginu')->where('member_id', $this->memberId)->whereNull('login')->exists());
        $this->acl();
        $this->as()->post(route('users.store'), ['member_id' => $this->memberId, 'login' => '', 'surname' => '', 'type' => 3])->assertSessionHasErrors(['login', 'surname', 'type', 'password']);
        $u = $new;

        $this->acl();
        $this->as()->get(route('users.edit', $u->id))->assertOk()->assertViewHas('canEditMember', true);
        $this->as()->put(route('users.update', $u->id), ['surname' => 'Upraveny', 'type' => 1, 'login' => "{$this->tag}m", 'comment' => 'kom', 'member_id' => $this->memberId])
            ->assertRedirect(route('users.show', $u->id))->assertSessionHas('success', 'Uživatel byl úspěšně upraven.');
        $r = DB::table('users')->where('id', $u->id)->first();
        $this->assertSame(['Upraveny', 1, "{$this->tag}m", 'kom'], [$r->surname, (int) $r->type, $r->login, $r->comment]);

        $this->acl(['edit_all/Users_Controller/login', 'edit_all/Users_Controller/comment', 'edit_all/Users_Controller/member']);
        $this->as()->put(route('users.update', $u->id), ['surname' => 'Znovu', 'type' => 1, 'login' => 'podvrzeny', 'comment' => 'podvrzeny', 'member_id' => 1]);
        $r = DB::table('users')->where('id', $u->id)->first();
        $this->assertSame(['Znovu', "{$this->tag}m", 'kom', $this->memberId], [$r->surname, $r->login, $r->comment, (int) $r->member_id], 'pole bez jemného práva se ignorují');
        $this->as()->put(route('users.update', $u->id), ['surname' => '', 'type' => 3])->assertSessionHasErrors(['surname', 'type']);
        $this->as()->put(route('users.update', 999999999), ['surname' => 'x', 'type' => 1])->assertNotFound();
    }

    /** Úprava uživatele heslo nemění — jen změna hesla (právo edit_all#password / sám s aktuálním). */
    public function test_uprava_nemeni_heslo(): void
    {
        $a = $this->user('a');
        $this->acl(['edit_all/Users_Controller/password']);
        $this->as()->put(route('users.update', $a), ['surname' => 'X', 'type' => 1, 'login' => "{$this->tag}a", 'member_id' => $this->memberId] + $this->pwd(\Tests\Support\Fake::secret('podvrzeneheslo')))
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(\Tests\Support\Fake::secret('puvodniheslo'), DB::table('users')->where('id', $a)->value('password')));
        $this->assertSame('X', DB::table('users')->where('id', $a)->value('surname'));
    }

    // ── smazání ──────────────────────────────────────────────────────────────

    public function test_smazani(): void
    {
        $a = $this->user('a');
        $b = $this->user('b');
        DB::table('devices')->insert(['name' => 'char', 'type' => 1, 'user_id' => $b]);

        $this->as()->from(route('users.show', $this->adminId))->delete(route('users.destroy', $this->adminId))->assertSessionHas('error', 'Nelze smazat vlastní účet.');
        $this->as()->from(route('users.show', $b))->delete(route('users.destroy', $b))->assertSessionHas('error', 'Uživatele nelze smazat, má přiřazená zařízení.');
        $this->as()->delete(route('users.destroy', $a))->assertRedirect(route('users.index'))->assertSessionHas('success', 'Uživatel byl úspěšně smazán.');
        $this->assertFalse(DB::table('users')->where('id', $a)->exists());
        DB::table('devices')->where('user_id', $b)->delete();
        $this->as()->from(route('users.show', $b))->delete(route('users.destroy', $b))->assertSessionHas('error', 'Uživatele nelze smazat, je jediným uživatelem člena.');
        $this->as()->delete(route('users.destroy', 999999999))->assertNotFound();
    }

    // ── heslo ────────────────────────────────────────────────────────────────

    public function test_zmena_hesla_sam_a_admin(): void
    {
        $a = $this->user('a');
        $this->as($a)->get(route('users.password', $a))->assertOk()->assertViewHas('isSelf', true)->assertViewHas('isAdmin', false);
        $this->as($a)->put(route('users.password.update', $a), $this->pwd(\Tests\Support\Fake::secret('noveheslo')))->assertSessionHasErrors('current_password');
        $this->as($a)->put(route('users.password.update', $a), ['current_password' => \Tests\Support\Fake::secret('puvodniheslo')] + $this->pwd(\Tests\Support\Fake::secret('noveheslo')))
            ->assertRedirect(route('members.show', $this->memberId))->assertSessionHas('success', 'Heslo bylo úspěšně změněno.');
        $this->assertTrue(Hash::check(\Tests\Support\Fake::secret('noveheslo'), DB::table('users')->where('id', $a)->value('password')));

        $b = $this->user('b');
        $this->as($a)->get(route('users.password', $b))->assertForbidden();
        $this->as()->put(route('users.password.update', $b), $this->pwd(\Tests\Support\Fake::secret('adminheslo')))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(\Tests\Support\Fake::secret('adminheslo'), DB::table('users')->where('id', $b)->value('password')), 'admin bez aktuálního hesla');
        $this->as()->get(route('users.password', 999999999))->assertNotFound();
    }

    public function test_tmavy_rezim(): void
    {
        $a = $this->user('a');
        DB::table('users')->where('id', $a)->update(['settings' => json_encode(['jine' => 1])]);
        $this->as($a)->postJson(route('user.dark-mode'), ['dark_mode' => 1])->assertExactJson(['ok' => true]);
        $this->assertSame(['jine' => 1, 'dark_mode' => 1], json_decode(DB::table('users')->where('id', $a)->value('settings'), true));
    }

    // ── ACL ──────────────────────────────────────────────────────────────────

    public function test_prava(): void
    {
        $a = $this->user('a');
        $this->acl(['view_all/Users_Controller/users', 'new_all/Users_Controller/users', 'edit_all/Users_Controller/users', 'delete_all/Users_Controller/users']);
        $this->as()->get(route('users.show', $a))->assertForbidden();
        $this->as()->get(route('users.by_member', $this->memberId))->assertForbidden();
        $this->as()->get(route('users.create'))->assertForbidden();
        $this->as()->post(route('users.store'), [])->assertForbidden();
        $this->as()->get(route('users.edit', $a))->assertForbidden();
        $this->as()->put(route('users.update', $a), [])->assertForbidden();
        $this->as()->delete(route('users.destroy', $a))->assertForbidden();
        $this->as()->post(route('users.login_unlock', $a))->assertForbidden();
        $this->as()->post(route('users.mfa.reset', $a))->assertForbidden();
        $this->assertTrue(DB::table('users')->where('id', $a)->exists());
    }
}
