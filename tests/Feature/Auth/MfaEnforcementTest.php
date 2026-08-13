<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\MfaEnforcementService;
use App\Services\MfaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * MFA fáze B — vynucení pro role: MfaEnforcementService (kdo vyžaduje) a
 * middleware EnsureMfa (forced enrollment). Vypnuté = no-op.
 */
class MfaEnforcementTest extends DatabaseTestCase
{
    private int $userId;
    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();

        $memberId = (int) DB::table('members')
            ->where('locked', 0)->where('type', 2)->where('leaving_date', '9999-12-31')->value('id');

        $this->userId = (int) DB::table('users')->insertGetId([
            'member_id' => $memberId, 'login' => 'mfab_' . uniqid(),
            'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'xxxxxxxx', 'settings' => '',
            'name' => 'MfaB', 'surname' => 'Test', 'comment' => '',
        ]);

        $this->groupId = (int) DB::table('aro_groups')->orderBy('id')->value('id');
        DB::table('groups_aro_map')->insertOrIgnore(['aro_id' => $this->userId, 'group_id' => $this->groupId]);
    }

    private function svc(): MfaEnforcementService
    {
        return app(MfaEnforcementService::class);
    }

    public function test_bez_oznacene_skupiny_nikdo_nevyzaduje(): void
    {
        $this->assertFalse($this->svc()->isRequiredForUser($this->userId));
    }

    public function test_clen_oznacene_skupiny_vyzaduje(): void
    {
        $this->svc()->setGroupRequired($this->groupId, true);
        $this->assertTrue($this->svc()->isRequiredForUser($this->userId));

        $this->svc()->setGroupRequired($this->groupId, false);
        $this->assertFalse($this->svc()->isRequiredForUser($this->userId));
    }

    public function test_pozadovana_role_bez_mfa_je_presmerovana_na_setup(): void
    {
        $this->svc()->setGroupRequired($this->groupId, true);

        $r = $this->actingAs(User::find($this->userId))->get('/');
        $r->assertRedirect(route('mfa.setup'));
    }

    public function test_pozadovana_role_bez_mfa_muze_na_setup(): void
    {
        $this->svc()->setGroupRequired($this->groupId, true);

        $this->actingAs(User::find($this->userId))->get(route('mfa.setup'))->assertOk();
    }

    public function test_pozadovana_role_s_mfa_a_passed_projde(): void
    {
        $this->svc()->setGroupRequired($this->groupId, true);
        app(MfaService::class)->enable($this->userId, app(MfaService::class)->generateSecret());

        $r = $this->actingAs(User::find($this->userId))
            ->withSession(['mfa_passed' => true])
            ->get(route('mfa.status'));
        $r->assertOk();
    }
}
