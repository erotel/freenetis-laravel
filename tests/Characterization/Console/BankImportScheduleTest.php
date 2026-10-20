<?php

namespace Tests\Characterization\Console;

use App\Domains\Billing\Actions\ImportNewFioPaymentsAction;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace plánovaného importu výpisů (bank:import-statements): účty
 * sdružení, pravidla stahování podle času (měsíčně, týdně, denně, pracovní
 * dny, každou hodinu, po srážce), --force / --account, záznamy do log_queues.
 */
class BankImportScheduleTest extends DatabaseTestCase
{
    private int $ba;
    private $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ba = (int) DB::table('bank_accounts')->insertGetId(['name' => 'Char FIO', 'member_id' => 1, 'account_nr' => (string) random_int(10000000, 99999999), 'bank_nr' => '2010']);
        Setting::set("fio_api_token_bank_account_{$this->ba}", \Tests\Support\Fake::secret('fio'));
        $this->importer = $this->mock(ImportNewFioPaymentsAction::class);
    }

    private function rule(int $type, ?string $attr): void
    {
        DB::table('bank_accounts_automatical_downloads')->insert(['bank_account_id' => $this->ba, 'type' => $type, 'attribute' => $attr, 'email_enabled' => 0, 'sms_enabled' => 0]);
    }

    private function import(string $at, array $opts = []): int
    {
        $this->travelTo($at);
        return $this->artisan('bank:import-statements', ['--account' => $this->ba] + $opts)->run();
    }

    private function logs(): array
    {
        return DB::table('log_queues')->where('description', 'like', "%#{$this->ba} %")->orderBy('id')->get(['type', 'description'])
            ->map(fn ($r) => [(int) $r->type, $r->description])->all();
    }

    /** Vyhodnocení pravidel pro daný okamžik (příkaz bere čas z time(), ne z Carbonu). */
    private function ruleMatches(int $type, ?string $attr, string $at): bool
    {
        $m = new \ReflectionMethod(\App\Console\Commands\ImportBankStatements::class, 'matchRules');
        return $m->invoke(app(\App\Console\Commands\ImportBankStatements::class), collect([(object) ['type' => $type, 'attribute' => $attr]]), strtotime($at));
    }

    public function test_pravidla_casu(): void
    {
        $cases = [
            [3, '12', '2099-03-10 12:55', true],      // denně ve 12
            [3, '12', '2099-03-10 11:55', false],
            [1, '31/9', '2099-02-28 09:55', true],    // měsíčně 31. → poslední den února
            [1, '31/9', '2099-02-27 09:55', false],
            [1, '0/9', '2099-03-01 09:55', true],     // den 0 → 1.
            [2, '3/8', '2099-03-11 08:55', true],     // středa
            [2, '3/8', '2099-03-12 08:55', false],
            [4, '7', '2099-03-14 07:55', false],      // sobota, jen pracovní dny
            [4, '7', '2099-03-13 07:55', true],
            [5, null, '2099-03-14 03:55', true],      // každou hodinu
            [9, '1', '2099-03-14 03:55', false],      // neznámý typ
        ];
        foreach ($cases as [$type, $attr, $at, $expect]) {
            $this->assertSame($expect, $this->ruleMatches($type, $attr, $at), "typ {$type} {$attr} @ {$at}");
        }

        // po srážce: jen se zapnutou automatickou srážkou, v deduct_day (krácený na délku měsíce)
        Setting::set('deduct_day', '30');
        Setting::set('deduct_fees_automatically_enabled', '0');
        $this->assertFalse($this->ruleMatches(6, '10', '2099-03-30 10:55'));
        Setting::set('deduct_fees_automatically_enabled', '1');
        $this->assertTrue($this->ruleMatches(6, '10', '2099-03-30 10:55'));
        $this->assertFalse($this->ruleMatches(6, '11', '2099-03-30 10:55'));
        $this->assertTrue($this->ruleMatches(6, '10', '2099-02-28 10:55'), 'únor: 30 → 28.');
    }

    public function test_bez_pravidel_a_nesplnene_pravidlo(): void
    {
        $this->importer->shouldNotReceive('handle');
        $this->assertSame(0, $this->import('2099-03-10 12:55'), 'bez pravidel přeskočí');
        $this->rule(9, '1');   // nikdy nesplněné
        $this->assertSame(0, $this->import('2099-03-10 12:55'));
    }

    public function test_force_a_logy(): void
    {
        $this->importer->shouldReceive('handle')->with($this->ba)->andReturn(['imported' => 2, 'skipped' => 1], ['imported' => 0, 'skipped' => 3]);
        $this->assertSame(0, $this->import('2099-03-10 12:55', ['--force' => true]), 'force bez pravidel');
        $this->import('2099-03-10 13:55', ['--force' => true]);
        $logs = $this->logs();
        $this->assertCount(1, $logs, 'log jen když něco přibylo');
        $this->assertSame(3, $logs[0][0], 'TYPE_INFO');
        $this->assertStringContainsString(': 2 new, 1 duplicates skipped.', $logs[0][1]);
    }

    public function test_bez_tokenu_preskoci(): void
    {
        Setting::set("fio_api_token_bank_account_{$this->ba}", '');
        $this->rule(5, null);
        $this->importer->shouldNotReceive('handle');
        $this->assertSame(0, $this->import('2099-03-10 12:55', ['--force' => true]));
    }

    public function test_chyby(): void
    {
        $this->importer->shouldReceive('handle')->andThrow(new \RuntimeException('FIO 500'));
        $this->assertSame(1, $this->import('2099-03-10 12:55', ['--force' => true]));
        $logs = $this->logs();
        $this->assertSame(1, $logs[0][0] ?? null, 'TYPE_ERROR');
    }
}
