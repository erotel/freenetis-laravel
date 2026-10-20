<?php

namespace Tests\Characterization\Billing;

use App\Models\Setting;
use App\Domains\Billing\Actions\ChargeOverdueFeesAction;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace dohnání poplatků po platbě (ChargeOverdueFeesAction) před
 * přesunem do domény Billing; doplňuje Feature/Fees/PaymentBackchargeTest.
 *
 * Měsíce od payment_blocked_since do dneška; den srážky = deduct_day oříznutý
 * na konec měsíce; nejdřív členský poplatek (type 1) za všechny měsíce, pak
 * příplatky (type 6) — jen když členské prošly; při nedostatku kreditu konec
 * a blokace zůstává. Strženo už → přeskočit (idempotence), nulový → přeskočit.
 */
class BackchargeTest extends DatabaseTestCase
{
    private int $apId;
    private int $orgId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apId  = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $this->orgId = (int) DB::table('accounts')->where('member_id', 1)->where('account_attribute_id', 221101)->value('id');
        Setting::set('deduct_day', 31);
        Setting::set('default_fee_member_type_2', $this->fee(300, 35));
    }

    private function fee(float $amount, int $type): int
    {
        return (int) DB::table('fees')->insertGetId(['readonly' => 0, 'fee' => $amount, 'from' => '2000-01-01', 'to' => '9999-12-31',
            'type_id' => $type, 'name' => 'char', 'special_type_id' => null]);
    }

    /** @return array{member:int, account:int} */
    private function blocked(float $balance, string $since, int $type = 2): array
    {
        $id = (int) DB::table('members')->insertGetId([
            'name' => 'Char BC', 'type' => $type, 'registration' => 1, 'locked' => 0, 'address_point_id' => $this->apId,
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31',
            'payment_blocked' => 1, 'payment_blocked_since' => $since, 'pending_termination' => 1,
        ]);
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => $balance, 'name' => 'c']);
        return ['member' => $id, 'account' => $acc];
    }

    private function charges(int $account, int $type): array
    {
        return DB::table('transfers')->where('origin_id', $account)->where('type', $type)->orderBy('datetime')
            ->get()->map(fn ($t) => [substr($t->datetime, 0, 10), (float) $t->amount, $t->text])->all();
    }

    private function backcharge(int $member, string $today): bool
    {
        return app(ChargeOverdueFeesAction::class)->handle($member, $today);
    }

    public function test_den_srazky_orizne_na_konec_mesice_a_sluzby_po_poplatcich(): void
    {
        $x = $this->blocked(10000, '2026-01-15');
        DB::table('members_fees')->insert(['fee_id' => $this->fee(50, 39), 'member_id' => $x['member'], 'activation_date' => '2020-01-01',
            'deactivation_date' => '9999-12-31', 'priority' => 1, 'comment' => 'c']);

        $this->assertTrue($this->backcharge($x['member'], '2026-03-31'));
        $text = 'Dohnání srážky po platbě (prepaid backcharge)';
        $this->assertSame([['2026-01-31', 300.0, $text], ['2026-02-28', 300.0, $text], ['2026-03-31', 300.0, $text]], $this->charges($x['account'], 1));
        $svc = 'Dohnání srážky po platbě – dodatečné služby';
        $this->assertSame([['2026-01-31', 50.0, $svc], ['2026-02-28', 50.0, $svc], ['2026-03-31', 50.0, $svc]], $this->charges($x['account'], 6));
        $this->assertEqualsWithDelta(10000 - 1050, (float) DB::table('accounts')->where('id', $x['account'])->value('balance'), 0.001);

        $m = DB::table('members')->where('id', $x['member'])->first();
        $this->assertSame([0, null, 0], [(int) $m->payment_blocked, $m->payment_blocked_since, (int) $m->pending_termination]);
        $t = DB::table('transfers')->where('origin_id', $x['account'])->first();
        $this->assertSame([$this->orgId, null, null], [(int) $t->destination_id, $t->member_id, $t->user_id]);

        $in  = (float) DB::table('transfers')->where('destination_id', $this->orgId)->sum('amount');
        $out = (float) DB::table('transfers')->where('origin_id', $this->orgId)->sum('amount');
        $this->assertEqualsWithDelta($in - $out, (float) DB::table('accounts')->where('id', $this->orgId)->value('balance'), 0.01, 'přepočet provozního účtu');

        $audit = DB::table('audit_logs')->where('auditable_type', 'members')->where('auditable_id', $x['member'])->where('action', 'backcharge')->first();
        $this->assertSame(['charged_count' => 6, 'unblocked' => true], json_decode($audit->new_values, true));
    }

    public function test_aktualni_mesic_pred_dnem_srazky_se_nestrhava(): void
    {
        Setting::set('deduct_day', 20);
        $x = $this->blocked(10000, '2026-02-01');
        $this->assertTrue($this->backcharge($x['member'], '2026-03-10'));
        $this->assertSame(['2026-02-20'], array_column($this->charges($x['account'], 1), 0));
    }

    public function test_nedostatek_na_sluzby_blokace_zustava_poplatky_strzeny(): void
    {
        $x = $this->blocked(320, '2026-03-01');
        DB::table('members_fees')->insert(['fee_id' => $this->fee(50, 39), 'member_id' => $x['member'], 'activation_date' => '2020-01-01',
            'deactivation_date' => '9999-12-31', 'priority' => 1, 'comment' => 'c']);
        $this->assertFalse($this->backcharge($x['member'], '2026-03-31'));
        $this->assertCount(1, $this->charges($x['account'], 1));
        $this->assertCount(0, $this->charges($x['account'], 6));
        $this->assertSame(1, (int) DB::table('members')->where('id', $x['member'])->value('payment_blocked'));
        $audit = DB::table('audit_logs')->where('auditable_type', 'members')->where('auditable_id', $x['member'])->where('action', 'backcharge')->first();
        $this->assertSame(['charged_count' => 1, 'unblocked' => false], json_decode($audit->new_values, true));
    }

    public function test_nedostatek_na_poplatek_sluzby_se_nezkousi(): void
    {
        $x = $this->blocked(400, '2026-02-01');
        DB::table('members_fees')->insert(['fee_id' => $this->fee(10, 39), 'member_id' => $x['member'], 'activation_date' => '2020-01-01',
            'deactivation_date' => '9999-12-31', 'priority' => 1, 'comment' => 'c']);
        $this->assertFalse($this->backcharge($x['member'], '2026-03-31'));
        $this->assertSame(['2026-02-28'], array_column($this->charges($x['account'], 1), 0));
        $this->assertCount(0, $this->charges($x['account'], 6), 'služby až po celých poplatcích');
    }

    public function test_uz_strzeno_a_osvobozeny_mesic_preskoci_opakovani_nic(): void
    {
        $x = $this->blocked(1000, '2026-01-01');
        DB::table('transfers')->insert(['origin_id' => $x['account'], 'destination_id' => $this->orgId, 'type' => 1, 'amount' => 300,
            'datetime' => '2026-01-31', 'creation_datetime' => now(), 'text' => 'srážka']);
        // únor osvobozen (individuální 0 jen v únoru)
        DB::table('members_fees')->insert(['fee_id' => $this->fee(0, 35), 'member_id' => $x['member'], 'activation_date' => '2026-02-01',
            'deactivation_date' => '2026-02-28', 'priority' => 1, 'comment' => 'c']);

        $this->assertTrue($this->backcharge($x['member'], '2026-03-31'));
        $this->assertSame([['2026-01-31', 300.0], ['2026-03-31', 300.0]], array_map(fn ($c) => [$c[0], $c[1]], $this->charges($x['account'], 1)));

        DB::table('members')->where('id', $x['member'])->update(['payment_blocked' => 1, 'payment_blocked_since' => '2026-01-01']);
        $this->assertTrue($this->backcharge($x['member'], '2026-03-31'));
        $this->assertCount(2, $this->charges($x['account'], 1), 'idempotentní');
        $this->assertSame(1, DB::table('audit_logs')->where('auditable_type', 'members')->where('auditable_id', $x['member'])->where('action', 'backcharge')->count(),
            'bez stržení bez auditu');
    }

    public function test_neni_co_dohanet(): void
    {
        $notBlocked = $this->blocked(1000, '2026-01-01');
        DB::table('members')->where('id', $notBlocked['member'])->update(['payment_blocked' => 0]);
        $this->assertFalse($this->backcharge($notBlocked['member'], '2026-03-31'));

        $noSince = $this->blocked(1000, '2026-01-01');
        DB::table('members')->where('id', $noSince['member'])->update(['payment_blocked_since' => null]);
        $this->assertFalse($this->backcharge($noSince['member'], '2026-03-31'));
        $this->assertSame(1, (int) DB::table('members')->where('id', $noSince['member'])->value('payment_blocked'), 'bez data blokace beze změny');

        $this->assertFalse($this->backcharge(999999999, '2026-03-31'));

        $noCredit = $this->blocked(1000, '2026-01-01');
        DB::table('accounts')->where('id', $noCredit['account'])->delete();
        $this->assertFalse($this->backcharge($noCredit['member'], '2026-03-31'));
    }
}
