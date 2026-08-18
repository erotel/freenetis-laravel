<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Regrese (audit 2026-08-18): editační formulář člena skrývá dropdown „Třída
 * rychlosti (QoS)" za jemné právo edit_all#Members_Controller#qos_ceil
 * (members/edit.blade.php), ALE MemberController::update() dřív ukládal
 * speed_class_id jen pod hrubým edit_all#members. Uživatel s editací člena, ale
 * bez qos_ceil (např. skupina Tech6), tak mohl podvrhnout speed_class_id v POST
 * requestu a změnit zákazníkovi rychlost, přestože pole ve formuláři nevidí.
 *
 * Oprava: update() aplikuje speed_class_id jen když má uživatel qos_ceil; jinak
 * ponechá stávající hodnotu beze změny.
 */
class MemberSpeedClassAclTest extends DatabaseTestCase
{
    private int $userId;
    private int $memberId;
    private string $memberName;
    private int $memberType;

    /** Dvě existující třídy rychlosti — výchozí a cílová (podvržená). */
    private const SPEED_BEFORE = 3;   // notorik
    private const SPEED_ATTACK = 5;   // Standard

    protected function setUp(): void
    {
        parent::setUp();

        $member = DB::table('members')
            ->where('locked', 0)
            ->whereNotNull('name')
            ->whereIn('type', array_keys(\App\Helpers\MemberType::labels()))
            ->first(['id', 'name', 'type']);

        $this->memberId   = (int) $member->id;
        $this->memberName = $member->name;
        $this->memberType = (int) $member->type;

        // Výchozí rychlost člena (v rámci transakce, rollbackne se).
        DB::table('members')->where('id', $this->memberId)
            ->update(['speed_class_id' => self::SPEED_BEFORE]);

        $this->userId = (int) DB::table('users')->insertGetId([
            'member_id'            => $this->memberId,
            'login'                => 'qos_' . uniqid(),
            'password'             => Hash::make('x'),
            'type'                 => 1,
            'application_password' => 'xxxxxxxx',
            'settings'             => '',
            'name'                 => 'Qos', 'surname' => 'Test', 'comment' => '',
        ]);
    }

    /**
     * Namockuje ACL: vše povoleno, kromě qos_ceil, které řídí parametr.
     */
    private function grantAcl(bool $allowQos): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(
            function ($userId, $aco, $section, $value) use ($allowQos) {
                if ($section === 'Members_Controller' && $value === 'qos_ceil') {
                    return $allowQos;
                }
                return true;
            }
        );
    }

    private function updateMember(int $speedClassId)
    {
        return $this->actingAs(User::find($this->userId))->put(
            route('members.update', $this->memberId),
            [
                'name'           => $this->memberName,
                'type'           => $this->memberType,
                'speed_class_id' => $speedClassId,
            ]
        );
    }

    public function test_bez_qos_ceil_nelze_podvrhnout_rychlost(): void
    {
        $this->grantAcl(false); // má editaci člena, NEMÁ qos_ceil (jako Tech6)

        $this->updateMember(self::SPEED_ATTACK);

        $actual = (int) DB::table('members')->where('id', $this->memberId)->value('speed_class_id');
        $this->assertSame(
            self::SPEED_BEFORE,
            $actual,
            'speed_class_id se nesmí změnit bez práva qos_ceil (byl podvržen POSTem)'
        );
    }

    public function test_s_qos_ceil_lze_rychlost_zmenit(): void
    {
        $this->grantAcl(true); // plné právo včetně qos_ceil

        $this->updateMember(self::SPEED_ATTACK);

        $actual = (int) DB::table('members')->where('id', $this->memberId)->value('speed_class_id');
        $this->assertSame(
            self::SPEED_ATTACK,
            $actual,
            's právem qos_ceil se rychlost změnit má'
        );
    }
}
