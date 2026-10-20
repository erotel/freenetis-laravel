<?php

namespace Tests\Feature\Members;

use App\Domains\Acl\AclService;
use App\Helpers\MemberType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Uvítací e-mail po schválení: řádný člen → šablona 10, zákazník nic
 * (dostává e-mail se smlouvou po podpisu).
 * Platí pro tlačítko Schválit i pro schválení změnou typu v editaci.
 */
class MemberApprovalEmailTest extends DatabaseTestCase
{
    private int $adminId;
    private int $apId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->apId    = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        if (!$this->adminId || !$this->apId || !DB::table('messages')->where('id', 10)->exists()) {
            $this->markTestSkipped('chybí hlavní uživatel / adresní bod / šablona 10');
        }
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnTrue();
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    /** @return array{0:int,1:string} [member id, e-mail] */
    private function member(int $type): array
    {
        $email = 'appr_' . uniqid() . '@example.test';
        $id = (int) DB::table('members')->insertGetId([
            'name' => 'Test Schválení', 'type' => $type, 'entrance_date' => null,
            'leaving_date' => '9999-12-31', 'registration' => 0, 'locked' => 0,
            'address_point_id' => $this->apId,
        ]);
        $uid = (int) DB::table('users')->insertGetId([
            'member_id' => $id, 'login' => 'appr_' . uniqid(), 'password' => Hash::make('x'),
            'type' => 1, 'application_password' => 'xxxxxxxx', 'settings' => '',
            'name' => 'Appr', 'surname' => 'Test', 'comment' => '',
        ]);
        $cid = (int) DB::table('contacts')->insertGetId(['type' => 20, 'value' => $email]);
        DB::table('users_contacts')->insert(['user_id' => $uid, 'contact_id' => $cid]);
        return [$id, $email];
    }

    private function edit(int $id, int $type): void
    {
        $this->actingAs(User::find($this->adminId))
            ->put(route('members.update', $id), ['name' => 'Test Schválení', 'type' => $type])
            ->assertRedirect(route('members.show', $id));
    }

    private function subjects(string $to): array
    {
        return DB::table('email_queues')->where('to', $to)->pluck('subject')->all();
    }

    private function templateName(int $id): string
    {
        return (string) DB::table('messages')->where('id', $id)->value('name');
    }

    public function test_editace_cekajici_clen_na_radneho_posle_sablonu_10(): void
    {
        [$id, $email] = $this->member(MemberType::PENDING_MEMBER);
        $this->edit($id, MemberType::REGULAR);

        $subjects = $this->subjects($email);
        $this->assertCount(1, $subjects);
        $this->assertStringEndsWith($this->templateName(10), $subjects[0]);
    }

    public function test_editace_cekajici_zakaznik_na_zakaznika_nic_neposle(): void
    {
        [$id, $email] = $this->member(MemberType::PENDING_CUSTOMER);
        $this->edit($id, MemberType::CUSTOMER);
        $this->assertSame([], $this->subjects($email));
    }

    public function test_editace_aktivniho_clena_nic_neposle(): void
    {
        [$id, $email] = $this->member(MemberType::REGULAR);
        $this->edit($id, MemberType::REGULAR);
        $this->assertSame([], $this->subjects($email));
    }

    public function test_editace_cekatele_bez_zmeny_typu_nic_neposle(): void
    {
        [$id, $email] = $this->member(MemberType::PENDING_MEMBER);
        $this->edit($id, MemberType::PENDING_MEMBER);
        $this->assertSame([], $this->subjects($email));
    }
}
