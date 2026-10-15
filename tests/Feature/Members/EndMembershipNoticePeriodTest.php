<?php

namespace Tests\Feature\Members;

use App\Models\Setting;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Ukončení s výpovědní lhůtou (incident .54, zákazník 6235, 2026-10-15):
 *  - ve výpovědní lhůtě (leaving_date v budoucnu) zůstává člen svého typu
 *    (zákazník 2 / řádný 90) a platí běžné poplatky; typ bývalého (15/16)
 *    nastaví až cron members:redirect-former v den odchodu;
 *  - vratka se zkracuje o poplatky ve lhůtě → z kreditu se převede JEN vracená
 *    částka, zbytek strhne srážka jako členský poplatek;
 *  - cron dává řádnému členovi 15 a ostatním 16 (dřív všem 15).
 */
class EndMembershipNoticePeriodTest extends DatabaseTestCase
{
    private int $adminId;
    private int $apId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->apId    = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        if (!$this->adminId || !$this->apId) {
            $this->markTestSkipped('chybí admin / adresní bod');
        }
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true);
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    /** @return array{member:int, account:int} */
    private function member(int $type, float $balance = 0, string $leaving = '9999-12-31'): array
    {
        $id = (int) DB::table('members')->insertGetId([
            'name' => 'Char End', 'type' => $type, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $this->apId, 'entrance_date' => '2020-01-01', 'leaving_date' => $leaving,
        ]);
        $acc = (int) DB::table('accounts')->insertGetId([
            'member_id' => $id, 'account_attribute_id' => 221100, 'balance' => $balance, 'name' => 'char',
        ]);
        DB::table('variable_symbols')->insert(['account_id' => $acc, 'variable_symbol' => (string) $id]);
        return ['member' => $id, 'account' => $acc];
    }

    private function row(int $id): object
    {
        return DB::table('members')->where('id', $id)->first();
    }

    private function vs(int $account): string
    {
        return (string) DB::table('variable_symbols')->where('account_id', $account)->value('variable_symbol');
    }

    // ── F2: cron v den odchodu ───────────────────────────────────────────────

    public function test_cron_zakaznik_na_16_radny_na_15_a_vs_plus_u(): void
    {
        $yesterday = now()->subDay()->format('Y-m-d');
        $customer = $this->member(2, 0, $yesterday);
        $regular  = $this->member(90, 0, $yesterday);
        $pending  = $this->member(18, 0, $yesterday);

        $this->artisan('members:redirect-former', ['--force' => true])->assertExitCode(0);

        $this->assertSame(16, (int) $this->row($customer['member'])->type);
        $this->assertSame(15, (int) $this->row($regular['member'])->type);
        $this->assertSame(16, (int) $this->row($pending['member'])->type);
        $this->assertSame(1, (int) $this->row($customer['member'])->locked);
        $this->assertStringEndsWith('+U', $this->vs($customer['account']));
        $this->assertStringEndsWith('+U', $this->vs($regular['account']));
    }

    public function test_cron_budouci_odchod_nesahne(): void
    {
        $x = $this->member(2, 0, now()->addDays(10)->format('Y-m-d'));
        $this->artisan('members:redirect-former', ['--force' => true])->assertExitCode(0);
        $this->assertSame(2, (int) $this->row($x['member'])->type);
        $this->assertStringEndsNotWith('+U', $this->vs($x['account']));
    }
}
