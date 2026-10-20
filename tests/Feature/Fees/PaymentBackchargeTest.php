<?php

namespace Tests\Feature\Fees;

use App\Models\Setting;
use App\Domains\Billing\Actions\ChargeOverdueFeesAction;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Dohnání poplatků po platbě (ChargeOverdueFeesAction): blokovaný člen
 * (payment_blocked) po příchozí platbě — strhne dlužné měsíce od
 * payment_blocked_since chronologicky, dokud kredit stačí; když projde vše,
 * odblokuje. Typy mimo účtování (žadatel, čekající, bývalí) jen odblokuje.
 */
class PaymentBackchargeTest extends DatabaseTestCase
{
    private int $apId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        if (!$this->apId || !DB::table('accounts')->where('member_id', 1)->where('account_attribute_id', 221101)->exists()) {
            $this->markTestSkipped('chybí adresní bod / účet organizace');
        }
        Setting::set('deduct_day', 1);
        Setting::set('default_fee_member_type_2', (int) DB::table('fees')->insertGetId([
            'readonly' => 0, 'fee' => 300, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => 35, 'name' => 'char', 'special_type_id' => null,
        ]));
    }

    /** @return array{member:int, account:int} */
    private function blocked(int $type, float $balance, string $since, array $m = []): array
    {
        $id = (int) DB::table('members')->insertGetId($m + [
            'name' => 'Char Back', 'type' => $type, 'registration' => 1, 'locked' => 0, 'address_point_id' => $this->apId,
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31',
            'payment_blocked' => 1, 'payment_blocked_since' => $since,
        ]);
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => $balance, 'name' => 'char']);
        return ['member' => $id, 'account' => $acc];
    }

    private function fees(int $account): array
    {
        return DB::table('transfers')->where('origin_id', $account)->where('type', 1)->orderBy('datetime')
            ->pluck('amount', 'datetime')->map(fn ($a) => (float) $a)->all();
    }

    public function test_dohna_dluzne_mesice_a_odblokuje(): void
    {
        $x = $this->blocked(2, 1000, '2099-01-01');
        $unblocked = app(ChargeOverdueFeesAction::class)->handle($x['member'], '2099-03-15');

        $this->assertTrue($unblocked);
        $this->assertSame(['2099-01-01 00:00:00' => 300.0, '2099-02-01 00:00:00' => 300.0, '2099-03-01 00:00:00' => 300.0], $this->fees($x['account']));
        $this->assertEqualsWithDelta(100, (float) DB::table('accounts')->where('id', $x['account'])->value('balance'), 0.001);
        $m = DB::table('members')->where('id', $x['member'])->first();
        $this->assertSame(0, (int) $m->payment_blocked);
        $this->assertNull($m->payment_blocked_since);
    }

    public function test_nedostatek_kreditu_dohna_cast_a_blokace_zustava(): void
    {
        $x = $this->blocked(2, 650, '2099-01-01');
        $unblocked = app(ChargeOverdueFeesAction::class)->handle($x['member'], '2099-03-15');

        $this->assertFalse($unblocked);
        $this->assertCount(2, $this->fees($x['account']), 'leden + únor, na březen nezbylo');
        $this->assertSame(1, (int) DB::table('members')->where('id', $x['member'])->value('payment_blocked'));
    }

    /** D1: čekající (17/18) / žadatel se neúčtuje → nic nestrhne, jen odblokuje. */
    public function test_cekatel_nic_nestrhne_jen_odblokuje(): void
    {
        $sc = (int) DB::table('speed_classes')->insertGetId(['name' => 'char ' . uniqid(), 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => 199]);
        foreach ([1, 17, 18] as $type) {
            $x = $this->blocked($type, 1000, '2099-01-01', ['speed_class_id' => $sc]);
            $this->assertTrue(app(ChargeOverdueFeesAction::class)->handle($x['member'], '2099-03-15'));
            $this->assertSame([], $this->fees($x['account']), "typ {$type}");
            $this->assertEqualsWithDelta(1000, (float) DB::table('accounts')->where('id', $x['account'])->value('balance'), 0.001);
        }
    }
}
