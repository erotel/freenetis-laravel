<?php

namespace Tests\Feature\Members;

use App\Helpers\MemberType;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/** Schválení (tlačítkem i editací) zapíše do auditu akci 'approved' se změnou typu. */
class MemberApprovalAuditTest extends DatabaseTestCase
{
    private int $adminId;
    private int $apId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->apId    = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        if (!$this->adminId || !$this->apId) {
            $this->markTestSkipped('chybí hlavní uživatel / adresní bod');
        }
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnTrue();
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function member(int $type, bool $registration = true): int
    {
        return (int) DB::table('members')->insertGetId([
            'name' => 'Test Audit', 'type' => $type, 'entrance_date' => null,
            'leaving_date' => '9999-12-31', 'registration' => $registration ? 1 : 0,
            'locked' => 0, 'address_point_id' => $this->apId,
        ]);
    }

    private function approvedLogs(int $id)
    {
        return DB::table('audit_logs')->where('auditable_type', 'members')
            ->where('auditable_id', $id)->where('action', 'approved')->get();
    }

    public function test_tlacitko_zapise_approved(): void
    {
        $id = $this->member(MemberType::PENDING_MEMBER);
        $this->actingAs(User::find($this->adminId))->post(route('members.approve', $id));

        $logs = $this->approvedLogs($id);
        $this->assertCount(1, $logs);
        $this->assertSame($this->adminId, (int) $logs[0]->user_id);
        $this->assertSame(['type' => MemberType::PENDING_MEMBER], json_decode($logs[0]->old_values, true));
        $this->assertSame(['type' => MemberType::REGULAR], json_decode($logs[0]->new_values, true));
    }

    public function test_editace_zapise_approved(): void
    {
        $id = $this->member(MemberType::PENDING_CUSTOMER, false);
        $this->actingAs(User::find($this->adminId))
            ->put(route('members.update', $id), ['name' => 'Test Audit', 'type' => MemberType::CUSTOMER]);

        $logs = $this->approvedLogs($id);
        $this->assertCount(1, $logs);
        $this->assertSame(['type' => MemberType::CUSTOMER], json_decode($logs[0]->new_values, true));
    }

    public function test_odmitnute_schvaleni_nezapise_nic(): void
    {
        $id = $this->member(MemberType::PENDING_MEMBER, false);
        $this->actingAs(User::find($this->adminId))->post(route('members.approve', $id));
        $this->assertCount(0, $this->approvedLogs($id));
    }
}
