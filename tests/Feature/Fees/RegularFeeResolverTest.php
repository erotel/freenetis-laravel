<?php

namespace Tests\Feature\Fees;

use App\Services\RegularFeeResolver;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * RegularFeeResolver: třístupňový fallback měsíčního poplatku
 * individuální → cena tarifu → základní dle typu; a rozdíl null vs. explicitní 0.
 */
class RegularFeeResolverTest extends DatabaseTestCase
{
    private const REGULAR_FEE_TYPE = 35; // enum_types 'regular member fee'

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = now()->toDateString();
    }

    /** Reálný člen bez individuálního 'regular member fee'. Vrací [id, type]. */
    private function memberWithoutIndividual(): array
    {
        foreach (DB::table('members')->where('id', '>', 1)->orderBy('id')->limit(100)->get(['id', 'type']) as $m) {
            if (RegularFeeResolver::individualFee((int) $m->id, $this->today) === null) {
                return [(int) $m->id, (int) $m->type];
            }
        }
        $this->markTestSkipped('nenašel jsem člena bez individuálního poplatku');
    }

    /** Člen s cenou tarifu a bez individuálního poplatku. Vrací [id, type, tarif]. */
    private function memberWithTariff(): array
    {
        $rows = DB::table('members as m')
            ->join('speed_classes as sc', 'sc.id', '=', 'm.speed_class_id')
            ->where('m.id', '>', 1)->whereNotNull('sc.price')
            ->orderBy('m.id')->limit(100)
            ->get(['m.id', 'm.type', 'sc.price']);
        foreach ($rows as $m) {
            if (RegularFeeResolver::individualFee((int) $m->id, $this->today) === null) {
                return [(int) $m->id, (int) $m->type, (float) $m->price];
            }
        }
        $this->markTestSkipped('nenašel jsem člena s tarifní cenou bez individuálního poplatku');
    }

    private function assignRegularFee(int $member, float $amount): void
    {
        $feeId = DB::table('fees')->insertGetId([
            'readonly' => 0, 'fee' => $amount, 'from' => '2020-01-01', 'to' => '9999-12-31',
            'type_id'  => self::REGULAR_FEE_TYPE, 'name' => 'Test individuální', 'special_type_id' => null,
        ]);
        DB::table('members_fees')->insert([
            'fee_id' => $feeId, 'member_id' => $member,
            'activation_date' => '2020-01-01', 'deactivation_date' => '9999-12-31',
            'priority' => 1, 'comment' => 'test',
        ]);
    }

    public function test_individualni_poplatek_se_precte(): void
    {
        [$member, $type] = $this->memberWithoutIndividual();

        $this->assignRegularFee($member, 123.0);

        $this->assertSame(123.0, RegularFeeResolver::individualFee($member, $this->today));
        $this->assertSame(123.0, RegularFeeResolver::amount($member, $type, $this->today));
    }

    public function test_individualni_ma_prednost_pred_tarifem(): void
    {
        [$member, $type, $tarif] = $this->memberWithTariff();

        // Bez individuálního → částka = cena tarifu.
        $this->assertSame($tarif, RegularFeeResolver::amount($member, $type, $this->today));

        // S individuálním (jiná hodnota) → má přednost.
        $this->assignRegularFee($member, $tarif + 100);
        $this->assertSame($tarif + 100, RegularFeeResolver::amount($member, $type, $this->today));
    }

    public function test_explicitni_nula_neucituje_dal(): void
    {
        [$member, $type, $tarif] = $this->memberWithTariff();
        $this->assertGreaterThan(0, $tarif, 'test dává smysl jen když tarif > 0');

        // Explicitní individuální 0 = „neúčtovat nic" — NEsmí propadnout na tarif.
        $this->assignRegularFee($member, 0.0);
        $this->assertSame(0.0, RegularFeeResolver::amount($member, $type, $this->today));
    }

    public function test_cekajici_typ18_mapuje_na_zakaznika_typ2(): void
    {
        // Žadatel/čekající zákazník (18) se účtuje jako zákazník (2).
        $this->assertSame(
            RegularFeeResolver::defaultFeeByType(2),
            RegularFeeResolver::defaultFeeByType(18)
        );
    }
}
