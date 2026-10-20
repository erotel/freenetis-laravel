<?php

namespace Tests\Feature\Fees;

use App\Domains\Acl\AclService;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Obnovení přerušeného členství (MemberController::restoreInterrupt) strhne
 * hned měsíční poplatek (chargeMonthlyFeeNow) — prepaid jako srážka.
 */
class RestoreInterruptChargeTest extends DatabaseTestCase
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
        Setting::set('default_fee_member_type_2', (int) DB::table('fees')->insertGetId([
            'readonly' => 0, 'fee' => 300, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => 35, 'name' => 'char', 'special_type_id' => null,
        ]));
    }

    /** Člen s aktivním přerušením. @return array{member:int, account:int} */
    private function interrupted(float $balance): array
    {
        $id = (int) DB::table('members')->insertGetId([
            'name' => 'Char Int', 'type' => 2, 'registration' => 1, 'locked' => 1, 'address_point_id' => $this->apId,
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31',
        ]);
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => $balance, 'name' => 'char']);
        $feeId = (int) DB::table('fees')->insertGetId([
            'readonly' => 0, 'fee' => 0, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => 35, 'name' => 'char přerušení', 'special_type_id' => 1,
        ]);
        $mf = (int) DB::table('members_fees')->insertGetId([
            'fee_id' => $feeId, 'member_id' => $id, 'activation_date' => now()->subMonth()->toDateString(),
            'deactivation_date' => now()->addMonths(3)->toDateString(), 'priority' => 0, 'comment' => 'char',
        ]);
        DB::table('membership_interrupts')->insert(['member_id' => $id, 'members_fee_id' => $mf, 'end_after_interrupt_end' => 0]);
        return ['member' => $id, 'account' => $acc];
    }

    private function restore(int $id)
    {
        return $this->actingAs(User::find($this->adminId))->post(route('members.restore-interrupt', $id));
    }

    public function test_obnoveni_strhne_poplatek_na_provozni_ucet_a_prepocita(): void
    {
        $x = $this->interrupted(1000);
        $this->restore($x['member'])->assertRedirect(route('members.show', $x['member']));

        $t = DB::table('transfers')->where('origin_id', $x['account'])->where('type', 1)->first();
        $this->assertNotNull($t);
        $this->assertEqualsWithDelta(300, (float) $t->amount, 0.001);
        $this->assertSame(now()->toDateString() . ' 00:00:00', $t->datetime);
        $this->assertSame('Doplatek za měsíc po obnovení přerušení', $t->text);
        $this->assertEqualsWithDelta(700, (float) DB::table('accounts')->where('id', $x['account'])->value('balance'), 0.001);

        // D2: stejný cíl jako měsíční srážka a dohnání — provozní účet organizace (221101)
        $operating = (int) DB::table('accounts')->where('member_id', 1)->where('account_attribute_id', 221101)->value('id');
        $this->assertSame($operating, (int) $t->destination_id);
        $in  = (float) DB::table('transfers')->where('destination_id', $operating)->sum('amount');
        $out = (float) DB::table('transfers')->where('origin_id', $operating)->sum('amount');
        $this->assertEqualsWithDelta($in - $out, (float) DB::table('accounts')->where('id', $operating)->value('balance'), 0.01);
    }

    public function test_obnoveni_bez_kreditu_zablokuje(): void
    {
        $x = $this->interrupted(100);
        $this->restore($x['member'])->assertRedirect();

        $this->assertFalse(DB::table('transfers')->where('origin_id', $x['account'])->where('type', 1)->exists());
        $this->assertSame(1, (int) DB::table('members')->where('id', $x['member'])->value('payment_blocked'));
    }
}
