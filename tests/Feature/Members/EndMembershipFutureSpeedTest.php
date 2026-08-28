<?php

namespace Tests\Feature\Members;

use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Regrese: ukončení členství s BUDOUCÍM datem (dohrání internetu do odjezdu)
 * dřív vynulovalo speed_class_id hned — zákazník tak přišel o rychlost ještě
 * předtím, než mu členství vůbec skončilo. Přitom RedirectFormerMembers Step 1
 * (který rychlost správně nuluje) tyhle členy přeskakuje, protože už mají typ
 * FORMER → nikdo jim ji v den D nedorovnal.
 *
 * Oprava:
 *  - endMembership nuluje rychlost jen když leaving_date <= dnes (jako `locked`);
 *  - u budoucího data tarif zůstává a cron RedirectFormerMembers ho vynuluje
 *    v den D (blok „locked dorovnání").
 */
class EndMembershipFutureSpeedTest extends DatabaseTestCase
{
    private int $userId;
    private int $memberId;
    private int $origType;

    private const SPEED = 5; // Standard

    protected function setUp(): void
    {
        parent::setUp();

        $member = DB::table('members')
            ->where('locked', 0)
            ->whereNotNull('name')
            ->whereIn('type', array_keys(\App\Helpers\MemberType::labels()))
            ->first(['id', 'type']);

        $this->memberId = (int) $member->id;
        $this->origType = (int) $member->type;

        DB::table('members')->where('id', $this->memberId)->update([
            'speed_class_id' => self::SPEED,
            'leaving_date'   => '9999-12-31',
        ]);

        $this->userId = (int) DB::table('users')->insertGetId([
            'member_id'            => $this->memberId,
            'login'                => 'endm_' . uniqid(),
            'password'             => Hash::make('x'),
            'type'                 => 1,
            'application_password' => 'xxxxxxxx',
            'settings'             => '',
            'name'                 => 'End', 'surname' => 'Test', 'comment' => '',
        ]);

        // ACL: vše povoleno (edit_all apod.).
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true);
    }

    private function endWith(string $leavingDate)
    {
        return $this->actingAs(User::find($this->userId))->post(
            route('members.end-membership.store', $this->memberId),
            ['leaving_date' => $leavingDate, 'end_mode' => 1] // bez emailu/vratky
        );
    }

    public function test_budouci_datum_ponecha_rychlost(): void
    {
        $future = now()->addDays(30)->format('Y-m-d');
        $this->endWith($future);

        $m = DB::table('members')->where('id', $this->memberId)
            ->first(['speed_class_id', 'locked', 'leaving_date']);

        $this->assertSame(self::SPEED, (int) $m->speed_class_id, 'u budoucího data rychlost zůstává');
        $this->assertSame(0, (int) $m->locked, 'u budoucího data se ještě nezamyká');
        $this->assertSame($future, $m->leaving_date);
    }

    public function test_dnesni_datum_rychlost_vynuluje(): void
    {
        $today = now()->format('Y-m-d');
        $this->endWith($today);

        $m = DB::table('members')->where('id', $this->memberId)
            ->first(['speed_class_id', 'locked']);

        $this->assertNull($m->speed_class_id, 'u dnešního data se rychlost vynuluje');
        $this->assertSame(1, (int) $m->locked, 'u dnešního data se zamyká');
    }

    public function test_cron_v_den_D_dorovna_rychlost(): void
    {
        // Ukončeno „včera" budoucím záměrem, ale teď už datum nastalo:
        // simulujeme stav po budoucím ukončení (FORMER, locked=0, rychlost drží).
        $newType = ($this->origType == \App\Helpers\MemberType::REGULAR)
            ? \App\Helpers\MemberType::FORMER
            : \App\Helpers\MemberType::FORMER_CUSTOMER;

        DB::table('members')->where('id', $this->memberId)->update([
            'type'           => $newType,
            'locked'         => 0,
            'leaving_date'   => now()->subDay()->format('Y-m-d'),
            'speed_class_id' => self::SPEED,
        ]);

        $this->artisan('members:redirect-former', ['--force' => true])->assertExitCode(0);

        $m = DB::table('members')->where('id', $this->memberId)
            ->first(['speed_class_id', 'locked']);

        $this->assertNull($m->speed_class_id, 'cron v den D rychlost dorovná na null');
        $this->assertSame(1, (int) $m->locked, 'cron v den D zamkne');
    }
}
