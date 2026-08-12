<?php

namespace Tests\Feature\Fees;

use App\Services\MemberFeesTermination;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Automatické datumové ukončení individuálních poplatků člena při odchodu
 * (MemberFeesTermination) + zpětné oživení při obnovení členství.
 */
class MemberFeesTerminationTest extends DatabaseTestCase
{
    private const REGULAR_FEE_TYPE = 35;

    private int $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = (int) DB::table('members')->where('id', '>', 1)->orderBy('id')->value('id');
    }

    private function assignFee(int $feeId, string $activation, string $deactivation): int
    {
        return DB::table('members_fees')->insertGetId([
            'fee_id' => $feeId, 'member_id' => $this->member,
            'activation_date' => $activation, 'deactivation_date' => $deactivation,
            'priority' => 1, 'comment' => 'test',
        ]);
    }

    private function makeRegularFee(): int
    {
        return DB::table('fees')->insertGetId([
            'readonly' => 0, 'archived' => 0, 'fee' => 100, 'from' => '2020-01-01', 'to' => '9999-12-31',
            'type_id' => self::REGULAR_FEE_TYPE, 'name' => 'Test tarif', 'special_type_id' => null,
        ]);
    }

    private function deactivationOf(int $mfId): string
    {
        return (string) DB::table('members_fees')->where('id', $mfId)->value('deactivation_date');
    }

    public function test_deactivate_ukonci_aktivni_k_datu_odchodu(): void
    {
        $mf = $this->assignFee($this->makeRegularFee(), '2020-01-01', '9999-12-31');

        $n = MemberFeesTermination::deactivate($this->member, '2026-06-30');

        $this->assertGreaterThanOrEqual(1, $n);
        $this->assertStringStartsWith('2026-06-30', $this->deactivationOf($mf));
    }

    public function test_deactivate_nechava_preruseni_clenstvi(): void
    {
        // #5 = existující poplatek „Přerušení členství" (special_type_id=1).
        $interruptFeeId = (int) DB::table('fees')->where('special_type_id', 1)->value('id');
        $this->assertNotNull($interruptFeeId, 'poplatek přerušení (special_type_id=1) musí existovat');

        $mfInterrupt = $this->assignFee($interruptFeeId, '2020-01-01', '9999-12-31');
        $mfNormal    = $this->assignFee($this->makeRegularFee(), '2020-01-01', '9999-12-31');

        MemberFeesTermination::deactivate($this->member, '2026-06-30');

        $this->assertStringStartsWith('9999', $this->deactivationOf($mfInterrupt), 'přerušení se nesmí dotknout');
        $this->assertStringStartsWith('2026-06-30', $this->deactivationOf($mfNormal));
    }

    public function test_deactivate_ignoruje_jiz_drive_ukoncene(): void
    {
        // Poplatek končící PŘED datem odchodu se nemění.
        $mf = $this->assignFee($this->makeRegularFee(), '2020-01-01', '2025-01-01');

        MemberFeesTermination::deactivate($this->member, '2026-06-30');

        $this->assertStringStartsWith('2025-01-01', $this->deactivationOf($mf));
    }

    public function test_reactivate_ozivi_poplatky_ukoncene_k_datu_odchodu(): void
    {
        $mf = $this->assignFee($this->makeRegularFee(), '2020-01-01', '9999-12-31');
        MemberFeesTermination::deactivate($this->member, '2026-06-30');
        $this->assertStringStartsWith('2026-06-30', $this->deactivationOf($mf));

        $n = MemberFeesTermination::reactivate($this->member, '2026-06-30');

        $this->assertGreaterThanOrEqual(1, $n);
        $this->assertStringStartsWith('9999', $this->deactivationOf($mf));
    }

    public function test_sentinel_datum_nic_nedela(): void
    {
        $mf = $this->assignFee($this->makeRegularFee(), '2020-01-01', '9999-12-31');

        $this->assertSame(0, MemberFeesTermination::deactivate($this->member, '9999-12-31'));
        $this->assertSame(0, MemberFeesTermination::deactivate($this->member, '0000-00-00'));
        $this->assertSame(0, MemberFeesTermination::deactivate($this->member, null));
        $this->assertStringStartsWith('9999', $this->deactivationOf($mf));
    }
}
