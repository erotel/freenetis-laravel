<?php

namespace Tests\Feature\Members;

use App\Helpers\MemberType;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Datum vstupu = den schválení. Čekající člen (17) registrací datum vstupu
 * nedostane; při schválení na řádného (90) se doplní dnešek. Ruční datum ve
 * formuláři se respektuje a běžná editace už aktivního člena datum nepřepisuje.
 */
class MemberApprovalEntranceDateTest extends DatabaseTestCase
{
    private int $uid;
    private int $apId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uid  = (int) DB::table('users')->where('type', 1)->value('id');
        $this->apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        if (!$this->uid || !$this->apId) {
            $this->markTestSkipped('chybí hlavní uživatel / adresní bod');
        }
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnTrue();
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    /** Vytvoří čekajícího člena (typ 17) s daným datem vstupu a vrátí jeho id. */
    private function pendingMember(?string $entrance): int
    {
        return (int) DB::table('members')->insertGetId([
            'name'             => 'Test Čekající',
            'type'             => MemberType::PENDING_MEMBER,
            'entrance_date'    => $entrance,
            'leaving_date'     => '9999-12-31',
            'registration'     => 0,
            'locked'           => 0,
            'address_point_id' => $this->apId,
        ]);
    }

    private function putMember(int $id, int $type, ?string $entrance)
    {
        return $this->actingAs(User::find($this->uid))
            ->put(route('members.update', $id), [
                'name'          => 'Test Čekající',
                'type'          => $type,
                'entrance_date' => $entrance,
            ]);
    }

    private function entrance(int $id): ?string
    {
        $v = DB::table('members')->where('id', $id)->value('entrance_date');
        return $v ? (string) $v : null;
    }

    public function test_schvaleni_doplni_dnesek(): void
    {
        $id = $this->pendingMember(null);          // registrace bez data
        $this->putMember($id, MemberType::REGULAR, null); // schválení bez ručního data
        $this->assertSame(now()->format('Y-m-d'), $this->entrance($id));
    }

    public function test_rucni_datum_pri_schvaleni_se_respektuje(): void
    {
        $id = $this->pendingMember(null);
        $this->putMember($id, MemberType::REGULAR, '2026-01-15');
        $this->assertSame('2026-01-15', $this->entrance($id));
    }

    public function test_bezna_editace_aktivniho_nepreptrepise(): void
    {
        // Už řádný člen s datem vstupu — běžná editace datum nechá být.
        $id = (int) DB::table('members')->insertGetId([
            'name' => 'Test Řádný', 'type' => MemberType::REGULAR,
            'entrance_date' => '2025-05-01', 'leaving_date' => '9999-12-31',
            'registration' => 0, 'locked' => 0, 'address_point_id' => $this->apId,
        ]);
        $this->putMember($id, MemberType::REGULAR, '2025-05-01');
        $this->assertSame('2025-05-01', $this->entrance($id));
    }
}
