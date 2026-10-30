<?php

namespace Tests\Feature\Security;

use App\Domains\Acl\AclService;
use App\Domains\Members\Auth\MfaService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Technik s edit_all na uživatele nesmí převzít účet s vyššími právy
 * (heslo, MFA, úprava, smazání) — jen účty, jejichž práva sám má.
 * Skutečný AclService, jen sady práv podvržené místo skupin v DB.
 */
class UserPrivilegeEscalationTest extends DatabaseTestCase
{
    private const MANAGE = ['view_all#Users_Controller#users', 'edit_all#Users_Controller#users', 'edit_all#Users_Controller#password',
        'delete_all#Users_Controller#users', 'edit_all#Users_Controller#login'];

    private int $tech;
    private int $sysadmin;
    private int $customer;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tag = 'pe' . substr(md5(uniqid('', true)), 0, 8);
        $member = (int) DB::table('members')->insertGetId(['name' => 'Char Priv', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $mk = fn (string $s) => (int) DB::table('users')->insertGetId(['member_id' => $member, 'login' => $this->tag . $s,
            'password' => Hash::make(\Tests\Support\Fake::secret('puvodni')), 'type' => 2, 'application_password' => 'x',
            'settings' => '', 'name' => 'J', 'surname' => 'N' . $s, 'comment' => '']);
        [$this->tech, $this->sysadmin, $this->customer] = [$mk('t'), $mk('s'), $mk('c')];

        $perms = [
            $this->tech     => self::MANAGE,
            $this->sysadmin => [...self::MANAGE, 'edit_all#Settings_Controller#finance_settings'],
            $this->customer => ['view_own#Users_Controller#users'],
        ];
        $this->app->instance(AclService::class, new class($perms) extends AclService {
            public function __construct(private array $perms) {}
            protected function getPermissionsForUser(int $userId): array { return $this->perms[$userId] ?? []; }
        });
    }

    private function as(int $id)
    {
        return $this->actingAs(User::find($id));
    }

    private function pwd(): array
    {
        $p = 'Xk9!' . \Tests\Support\Fake::secret('noveheslo');
        return ['password' => $p, 'password_confirmation' => $p];
    }

    public function test_technik_neprevezme_sysadmina(): void
    {
        $hash = DB::table('users')->where('id', $this->sysadmin)->value('password');
        $mfa = app(MfaService::class);
        $mfa->enable($this->sysadmin, $mfa->generateSecret());

        $this->as($this->tech)->get(route('users.password', $this->sysadmin))->assertForbidden();
        $this->as($this->tech)->put(route('users.password.update', $this->sysadmin), $this->pwd())->assertForbidden();
        $this->as($this->tech)->post(route('users.mfa.reset', $this->sysadmin))->assertForbidden();
        $this->as($this->tech)->get(route('users.edit', $this->sysadmin))->assertForbidden();
        $this->as($this->tech)->put(route('users.update', $this->sysadmin), ['surname' => 'X', 'type' => 2, 'login' => $this->tag . 'own'])->assertForbidden();
        $this->as($this->tech)->delete(route('users.destroy', $this->sysadmin))->assertForbidden();

        $this->assertSame($hash, DB::table('users')->where('id', $this->sysadmin)->value('password'));
        $this->assertSame($this->tag . 's', DB::table('users')->where('id', $this->sysadmin)->value('login'));
        $this->assertTrue($mfa->isEnabled($this->sysadmin));

        $html = $this->as($this->tech)->get(route('users.show', $this->sysadmin))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('users.password', $this->sysadmin), $html);
        $this->assertStringNotContainsString(route('users.mfa.reset', $this->sysadmin), $html);
    }

    public function test_vlastni_pravo_pokryje_pravo_ke_vsem(): void
    {
        $acl = app(AclService::class);
        $this->assertTrue($acl->canManageUser($this->tech, $this->customer), 'view_own pokryje view_all');
        $this->assertFalse($acl->canManageUser($this->customer, $this->tech));
        $this->assertTrue($acl->canManageUser($this->customer, $this->customer));
    }

    public function test_technik_spravuje_zakaznika_sysadmin_vsechny(): void
    {
        $mfa = app(MfaService::class);
        $mfa->enable($this->customer, $mfa->generateSecret());
        $this->as($this->tech)->put(route('users.password.update', $this->customer), $this->pwd())->assertSessionHasNoErrors()->assertRedirect();
        $this->as($this->tech)->post(route('users.mfa.reset', $this->customer))->assertSessionHas('success');
        $this->assertFalse($mfa->isEnabled($this->customer));

        $this->as($this->sysadmin)->put(route('users.password.update', $this->tech), $this->pwd())->assertSessionHasNoErrors()->assertRedirect();
        $this->as($this->sysadmin)->get(route('users.edit', $this->tech))->assertOk();
    }
}
