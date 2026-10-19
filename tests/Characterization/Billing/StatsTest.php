<?php

namespace Tests\Characterization\Billing;

use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace statistik (StatsController) před přesunem do domén:
 * přírůstek / úbytek a stav členů po měsících (24 měsíců), platby a
 * poplatky za rok, cashflow stržených poplatků podle typu člena (rok /
 * posledních 12 měsíců, cache), ACL.
 */
class StatsTest extends DatabaseTestCase
{
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        Cache::flush();
        $this->acl();
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function as()
    {
        return $this->actingAs(User::find($this->adminId));
    }

    private function member(int $type, string $entrance, string $leaving): int
    {
        return (int) DB::table('members')->insertGetId(['name' => 'Char Stats', 'type' => $type, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => $entrance, 'leaving_date' => $leaving]);
    }

    public function test_prehled(): void
    {
        $month = now()->format('Y-m');
        $before = $this->as()->get(route('stats.index'))->assertOk()->assertViewIs('stats.index');
        $i = array_search($month, $before->viewData('months24'), true);
        $this->assertSame(23, $i, 'posledních 24 měsíců, aktuální poslední');
        $this->assertSame(sprintf('%d-01', now()->year), $before->viewData('payMonths')[0]);
        $this->assertCount(12, $before->viewData('payData'));

        $this->member(2, now()->toDateString(), '9999-12-31');
        $this->member(2, '2020-01-01', now()->toDateString());
        $after = $this->as()->get(route('stats.index'));
        $this->assertSame($before->viewData('incrData')[$i] + 1, $after->viewData('incrData')[$i]);
        $this->assertSame($before->viewData('decrData')[$i] + 1, $after->viewData('decrData')[$i]);
        $this->assertSame($before->viewData('growthData')[$i] + 2, $after->viewData('growthData')[$i], 'oba aktivní v tomto měsíci');
    }

    /** Aktivní je i člen s legacy leaving_date 0000-00-00 (= bez odchodu; IS NULL na něj v MariaDB sedí také). */
    public function test_aktivni_vcetne_legacy_bez_odchodu(): void
    {
        $i = 23;
        $before = $this->as()->get(route('stats.index'))->viewData('growthData')[$i];
        $id = $this->member(2, '2020-01-01', '9999-12-31');
        $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;   // legacy nulové datum strict mode nepustí
        DB::statement("SET SESSION sql_mode = ''");
        DB::table('members')->where('id', $id)->update(['leaving_date' => '0000-00-00']);
        DB::statement('SET SESSION sql_mode = ?', [$mode]);
        $this->assertSame($before + 1, $this->as()->get(route('stats.index'))->viewData('growthData')[$i]);
    }

    /** Přijaté platby = jen příchozí platby členů (684000 → banka), ne interní převody ani srážky. */
    public function test_prijate_platby_jen_od_clenu(): void
    {
        $bank = (int) DB::table('accounts')->where('account_attribute_id', 221000)->value('id');
        $income = (int) DB::table('accounts')->where('account_attribute_id', 684000)->value('id');
        $credit = (int) DB::table('accounts')->where('account_attribute_id', 221100)->value('id');
        if (!$bank || !$income || !$credit) {
            $this->markTestSkipped('chybí účty 221000 / 684000 / 221100');
        }
        $m = (int) now()->format('n') - 1;
        $before = $this->as()->get(route('stats.index'))->viewData('payData')[$m];
        $t = fn (int $from, int $to, ?int $type, float $amount) => DB::table('transfers')->insert(['origin_id' => $from, 'destination_id' => $to, 'type' => $type,
            'amount' => $amount, 'datetime' => now(), 'creation_datetime' => now(), 'text' => 'char']);
        $t($income, $bank, null, 500);      // platba člena na banku
        $t($bank, $credit, null, 500);      // podvojný protizápis na kredit — nepočítat
        $t($credit, $bank, 1, 300);         // srážka poplatku — nepočítat
        $this->assertEqualsWithDelta($before + 500, $this->as()->get(route('stats.index'))->viewData('payData')[$m], 0.001);
    }

    public function test_cashflow_strzene_poplatky(): void
    {
        $c = $this->member(2, '2020-01-01', '9999-12-31');
        $r = $this->member(90, '2020-01-01', '9999-12-31');
        $acc = fn (int $m) => (int) DB::table('accounts')->insertGetId(['member_id' => $m, 'account_attribute_id' => 221100, 'balance' => 0, 'name' => 'c']);
        [$ac, $ar] = [$acc($c), $acc($r)];
        $org = (int) DB::table('accounts')->where('member_id', 1)->value('id');
        $t = fn (int $from, int $type, float $amount, string $dt) => DB::table('transfers')->insert(['origin_id' => $from, 'destination_id' => $org, 'type' => $type,
            'amount' => $amount, 'datetime' => $dt, 'creation_datetime' => $dt, 'text' => 'char']);
        $t($ac, 1, 300, '2099-03-05');
        $t($ac, 5, 50, '2099-03-20');
        $t($ar, 1, 100, '2099-03-05');
        $t($ac, 6, 999, '2099-03-05');   // dodatečná služba se nepočítá
        $t($ac, 1, 300, '2099-04-05');

        $res = $this->as()->get(route('stats.cashflow', ['year' => 2099]))->assertOk()->assertViewIs('stats.cashflow');
        $rows = collect($res->viewData('rows'))->keyBy('month');
        $this->assertSame('2099-12', $res->viewData('rows')[0]['month'], 'nejnovější nahoře');
        $this->assertSame([2 => 350.0, 90 => 100.0], $rows['2099-03']['deducted']);
        $this->assertSame([2 => 300.0, 90 => 0.0], $rows['2099-04']['deducted']);
        $this->assertSame(2099, $res->viewData('year'));
        $this->assertContains(2099, $res->viewData('availableYears'));

        $rolling = $this->as()->get(route('stats.cashflow'))->assertOk();
        $this->assertSame([0, 12, now()->format('Y-m')], [$rolling->viewData('year'), count($rolling->viewData('rows')), $rolling->viewData('rows')[0]['month']]);

        $t($ac, 1, 1000, '2099-03-06');
        $this->assertSame(350.0, collect($this->as()->get(route('stats.cashflow', ['year' => 2099]))->viewData('rows'))->keyBy('month')['2099-03']['deducted'][2], 'cache 10 min');
    }

    public function test_prava(): void
    {
        $this->acl(['view_all/Stats_Controller/members_growth']);
        $this->as()->get(route('stats.index'))->assertForbidden();
        $this->as()->get(route('stats.cashflow'))->assertForbidden();
    }
}
