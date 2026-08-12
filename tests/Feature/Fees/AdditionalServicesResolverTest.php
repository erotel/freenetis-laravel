<?php

namespace Tests\Feature\Fees;

use App\Services\AdditionalServicesResolver;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * AdditionalServicesResolver = jedna pravda pro dodatečné služby člena
 * (součet aktivních poplatků typu „additional service", enum_types#39).
 *
 * `members_fees.member_id` má FK na `members`, proto pracujeme s reálným členem,
 * který zatím žádné dodatečné služby nemá; vše se na konci rollbackuje.
 */
class AdditionalServicesResolverTest extends DatabaseTestCase
{
    private int $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = $this->findMemberWithoutServices();
    }

    /** Najde reálného člena bez aktivních dodatečných služeb. */
    private function findMemberWithoutServices(): int
    {
        $today = now()->toDateString();
        foreach (DB::table('members')->where('id', '>', 1)->orderBy('id')->limit(100)->pluck('id') as $id) {
            if (AdditionalServicesResolver::total((int) $id, $today) === 0.0
                && AdditionalServicesResolver::items((int) $id, $today) === []) {
                return (int) $id;
            }
        }
        $this->markTestSkipped('nenašel jsem člena bez dodatečných služeb');
    }

    /** Vloží poplatek typu „additional service" + jeho přiřazení členovi. */
    private function assignService(string $name, float $price, int $priority, string $from = '2020-01-01', string $to = '9999-12-31'): void
    {
        $feeId = DB::table('fees')->insertGetId([
            'readonly' => 0, 'fee' => $price, 'from' => '2020-01-01', 'to' => '9999-12-31',
            'type_id'  => 39, 'name' => $name, 'special_type_id' => null,
        ]);
        DB::table('members_fees')->insert([
            'fee_id' => $feeId, 'member_id' => $this->member,
            'activation_date' => $from, 'deactivation_date' => $to,
            'priority' => $priority, 'comment' => 'test',
        ]);
    }

    public function test_total_scita_aktivni_sluzby(): void
    {
        $today = now()->toDateString();
        $this->assertSame(0.0, AdditionalServicesResolver::total($this->member, $today), 'čistý člen = 0');

        $this->assignService('Veřejná IP', 50, 1);
        $this->assignService('Statická IP', 30, 2);

        $this->assertSame(80.0, AdditionalServicesResolver::total($this->member, $today));
    }

    public function test_items_vraci_polozky_serazene_dle_priority(): void
    {
        $this->assignService('Druhá', 30, 2);
        $this->assignService('První', 50, 1);

        $items = AdditionalServicesResolver::items($this->member, now()->toDateString());

        $this->assertCount(2, $items);
        $this->assertSame('První', $items[0]['name']);   // priority 1 první
        $this->assertSame(50.0, $items[0]['fee']);
        $this->assertSame('Druhá', $items[1]['name']);
    }

    public function test_sluzba_mimo_platnost_se_nepocita(): void
    {
        // Služba deaktivovaná v minulosti → k dnešku neplatí.
        $this->assignService('Stará', 99, 1, from: '2020-01-01', to: '2020-12-31');

        $this->assertSame(0.0, AdditionalServicesResolver::total($this->member, now()->toDateString()));
    }
}
