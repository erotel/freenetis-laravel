<?php

namespace Tests\Feature\Fees;

use App\Models\Fee;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Archivace tarifu: skryje ho z nabídky pro přiřazení poplatku členovi,
 * ale nic nemaže — poplatek i historická přiřazení v members_fees zůstávají.
 */
class FeeArchiveTest extends DatabaseTestCase
{
    private const REGULAR_FEE_TYPE = 35;

    private function makeFee(bool $archived): int
    {
        return DB::table('fees')->insertGetId([
            'readonly' => 0, 'archived' => $archived ? 1 : 0,
            'fee' => 111, 'from' => '2020-01-01', 'to' => '9999-12-31',
            'type_id' => self::REGULAR_FEE_TYPE, 'name' => 'Test tarif', 'special_type_id' => null,
        ]);
    }

    /** Stejný dotaz, jaký používá MemberFeeController pro nabídku poplatků. */
    private function assignableIds(): array
    {
        return Fee::assignable()->pluck('id')->all();
    }

    private function makeSystemFee(int $specialType): int
    {
        return DB::table('fees')->insertGetId([
            'readonly' => 1, 'archived' => 0, 'fee' => 0, 'from' => '2020-01-01', 'to' => '9999-12-31',
            'type_id' => self::REGULAR_FEE_TYPE, 'name' => 'Sys', 'special_type_id' => $specialType,
        ]);
    }

    public function test_archivovany_neni_v_nabidce_ale_aktivni_ano(): void
    {
        $active   = $this->makeFee(archived: false);
        $archived = $this->makeFee(archived: true);

        $ids = $this->assignableIds();

        $this->assertContains($active, $ids, 'nearchivovaný tarif má být v nabídce');
        $this->assertNotContains($archived, $ids, 'archivovaný tarif nesmí být v nabídce');
    }

    public function test_archivace_zachova_prirazeni_clenum(): void
    {
        // Reálný člen (FK members_fees→members).
        $memberId = (int) DB::table('members')->where('id', '>', 1)->orderBy('id')->value('id');
        $this->assertNotNull($memberId, 'potřebuji aspoň jednoho člena');

        $feeId = $this->makeFee(archived: false);
        DB::table('members_fees')->insert([
            'fee_id' => $feeId, 'member_id' => $memberId,
            'activation_date' => '2020-01-01', 'deactivation_date' => '9999-12-31',
            'priority' => 1, 'comment' => 'test',
        ]);

        // Archivace = pouhé nastavení příznaku, žádné mazání.
        Fee::whereKey($feeId)->update(['archived' => true]);

        $this->assertTrue((bool) Fee::find($feeId)->archived);
        $this->assertSame(1, DB::table('members_fees')->where('fee_id', $feeId)->count(),
            'přiřazení členovi musí po archivaci zůstat');
    }

    public function test_hasActiveAssignments_rozlisuje_aktivni_a_prosle(): void
    {
        $memberId = (int) DB::table('members')->where('id', '>', 1)->orderBy('id')->value('id');

        $feeActive = $this->makeFee(archived: false);
        DB::table('members_fees')->insert([
            'fee_id' => $feeActive, 'member_id' => $memberId,
            'activation_date' => '2020-01-01', 'deactivation_date' => '9999-12-31',
            'priority' => 1, 'comment' => 'test',
        ]);

        $feeExpired = $this->makeFee(archived: false);
        DB::table('members_fees')->insert([
            'fee_id' => $feeExpired, 'member_id' => $memberId,
            'activation_date' => '2020-01-01', 'deactivation_date' => '2020-12-31',
            'priority' => 1, 'comment' => 'test',
        ]);

        $feeNone = $this->makeFee(archived: false);

        $this->assertTrue(Fee::find($feeActive)->hasActiveAssignments(), 'aktivní přiřazení');
        $this->assertFalse(Fee::find($feeExpired)->hasActiveAssignments(), 'jen prošlé přiřazení');
        $this->assertFalse(Fee::find($feeNone)->hasActiveAssignments(), 'žádné přiřazení');
    }

    public function test_activeAssignmentsCount_pocita_jen_aktivni(): void
    {
        $memberIds = DB::table('members')->where('id', '>', 1)->orderBy('id')->limit(2)->pluck('id')->all();
        $this->assertCount(2, $memberIds, 'potřebuji aspoň dva členy');

        $feeId = $this->makeFee(archived: false);
        // dvě aktivní + jedno prošlé přiřazení
        DB::table('members_fees')->insert([
            ['fee_id' => $feeId, 'member_id' => $memberIds[0], 'activation_date' => '2020-01-01', 'deactivation_date' => '9999-12-31', 'priority' => 1, 'comment' => 'test'],
            ['fee_id' => $feeId, 'member_id' => $memberIds[1], 'activation_date' => '2020-01-01', 'deactivation_date' => '9999-12-31', 'priority' => 1, 'comment' => 'test'],
            ['fee_id' => $feeId, 'member_id' => $memberIds[0], 'activation_date' => '2019-01-01', 'deactivation_date' => '2019-12-31', 'priority' => 1, 'comment' => 'test'],
        ]);

        $this->assertSame(2, Fee::find($feeId)->activeAssignmentsCount());
    }

    public function test_osvobozen_od_poplatku_je_v_nabidce_i_kdyz_readonly(): void
    {
        // Systémový, ale ručně přiřaditelný (special_type_id=2).
        $feeFree = $this->makeSystemFee(Fee::SPECIAL_FEE_FREE);
        $this->assertContains($feeFree, $this->assignableIds(),
            '„Osvobozen od poplatku" musí jít přiřadit i přes readonly');
    }

    public function test_preruseni_clenstvi_neni_v_nabidce(): void
    {
        // Systémový, spravovaný vlastní funkcí (special_type_id=1) → mimo nabídku.
        $interrupt = $this->makeSystemFee(Fee::SPECIAL_INTERRUPT);
        $this->assertNotContains($interrupt, $this->assignableIds(),
            '„Přerušení členství" se ručně nepřiřazuje');
    }

    public function test_obnoveni_vrati_do_nabidky(): void
    {
        $feeId = $this->makeFee(archived: true);
        $this->assertNotContains($feeId, $this->assignableIds());

        Fee::whereKey($feeId)->update(['archived' => false]);

        $this->assertContains($feeId, $this->assignableIds());
    }
}
