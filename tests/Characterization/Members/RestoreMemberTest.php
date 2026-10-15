<?php

namespace Tests\Characterization\Members;

use App\Models\Setting;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace: obnovení bývalého člena (MemberController::restore), obnovení
 * přerušeného členství (::restoreInterrupt) a předvyplnění formuláře Ukončit
 * (::endMembershipForm) před přesunem do domény Members.
 * Poplatek po obnovení přerušení: Feature/Fees/RestoreInterruptChargeTest.
 *
 * Podivnost zachycená testem: restore mapuje typy NAOPAK — bývalý zákazník
 * (16) → řádný člen (90), bývalý člen (15) → zákazník (2); hláška to opakuje.
 * Obnovení nechává VS s +U a rychlost „žádná".
 */
class RestoreMemberTest extends DatabaseTestCase
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
        $acl->shouldReceive('hasAccess')->andReturn(true)->byDefault();
        $acl->shouldReceive('flushUserCache')->andReturnNull();
        Setting::set('default_fee_member_type_2', $this->fee(300));
    }

    private function fee(float $amount, ?int $special = null): int
    {
        return (int) DB::table('fees')->insertGetId([
            'readonly' => 0, 'fee' => $amount, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => 35, 'name' => 'char', 'special_type_id' => $special,
        ]);
    }

    private function member(int $type, array $m = []): int
    {
        return (int) DB::table('members')->insertGetId($m + [
            'name' => 'Char Rest', 'type' => $type, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $this->apId, 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31',
        ]);
    }

    private function as()
    {
        return $this->actingAs(User::find($this->adminId))->from(route('members.index'));
    }

    // ── obnovení bývalého ────────────────────────────────────────────────────

    public function test_obnoveni_byvaleho_typy_naopak_a_poplatky_ozivi(): void
    {
        $leaving = '2026-05-31';
        $customer = $this->member(16, ['locked' => 1, 'leaving_date' => $leaving]);
        $regular  = $this->member(15, ['locked' => 1, 'leaving_date' => $leaving]);
        $auto   = (int) DB::table('members_fees')->insertGetId(['fee_id' => $this->fee(100), 'member_id' => $customer, 'activation_date' => '2020-01-01', 'deactivation_date' => $leaving, 'priority' => 1, 'comment' => 'char']);
        $manual = (int) DB::table('members_fees')->insertGetId(['fee_id' => $this->fee(50), 'member_id' => $customer, 'activation_date' => '2020-01-01', 'deactivation_date' => '2026-03-31', 'priority' => 2, 'comment' => 'char']);

        $this->as()->post(route('members.restore', $customer))->assertRedirect(route('members.show', $customer))
            ->assertSessionHas('success', 'Člen byl obnoven jako zákazník.');
        $this->as()->post(route('members.restore', $regular))->assertSessionHas('success', 'Člen byl obnoven jako člen.');

        $c = DB::table('members')->where('id', $customer)->first();
        $this->assertSame([90, 0, '9999-12-31'], [(int) $c->type, (int) $c->locked, $c->leaving_date], 'podivnost: 16 → 90');
        $this->assertSame(2, (int) DB::table('members')->where('id', $regular)->value('type'), 'podivnost: 15 → 2');

        $this->assertSame('9999-12-31', DB::table('members_fees')->where('id', $auto)->value('deactivation_date'), 'ukončený k odchodu se oživí');
        $this->assertSame('2026-03-31', DB::table('members_fees')->where('id', $manual)->value('deactivation_date'), 'ručně ukončený zůstává');

        $audit = DB::table('audit_logs')->where('auditable_type', 'members')->where('auditable_id', $customer)->where('action', 'updated')->first();
        $this->assertSame(['type' => 16, 'locked' => 1, 'leaving_date' => $leaving], json_decode($audit->old_values, true));
        $this->assertSame(['type' => 90, 'locked' => 0, 'leaving_date' => '9999-12-31', 'note' => 'obnovení bývalého člena'], json_decode($audit->new_values, true));
    }

    public function test_obnoveni_aktivniho_chyba(): void
    {
        $id = $this->member(2);
        $this->as()->post(route('members.restore', $id))->assertRedirect(route('members.index'))
            ->assertSessionHas('error', 'Tento člen není označen jako bývalý.');
        $this->assertSame(2, (int) DB::table('members')->where('id', $id)->value('type'));
    }

    public function test_obnoveni_bez_prava_403_a_404(): void
    {
        $this->as()->post(route('members.restore', 999999999))->assertNotFound();
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(false);
        $acl->shouldReceive('flushUserCache')->andReturnNull();
        $this->as()->post(route('members.restore', $this->member(16)))->assertForbidden();
        $this->as()->post(route('members.restore-interrupt', $this->member(2)))->assertForbidden();
    }

    // ── obnovení přerušení ───────────────────────────────────────────────────

    /** @return array{member:int, account:int, mf:int, ip:int} */
    private function interrupted(float $balance, int $endAfter = 1): array
    {
        $id  = $this->member(2, ['locked' => 1, 'leaving_date' => now()->addMonths(3)->toDateString(),
            'payment_blocked' => 1, 'payment_blocked_since' => '2026-01-01', 'pending_termination' => 1]);
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => $balance, 'name' => 'char']);
        $mf  = (int) DB::table('members_fees')->insertGetId([
            'fee_id' => $this->fee(0, 1), 'member_id' => $id, 'activation_date' => now()->subMonth()->toDateString(),
            'deactivation_date' => now()->addMonths(3)->toDateString(), 'priority' => 0, 'comment' => 'char',
        ]);
        DB::table('membership_interrupts')->insert(['member_id' => $id, 'members_fee_id' => $mf, 'end_after_interrupt_end' => $endAfter]);

        $uid = (int) DB::table('users')->insertGetId(['member_id' => $id, 'login' => 'charrest_' . uniqid(), 'password' => 'x', 'type' => 1,
            'application_password' => 'xxxxxxxx', 'settings' => '', 'name' => 'C', 'surname' => 'R', 'comment' => '']);
        $dev   = (int) DB::table('devices')->insertGetId(['name' => 'char-rest', 'type' => 1, 'user_id' => $uid]);
        $iface = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'device_id' => $dev, 'name' => 'char']);
        $subnet = (int) DB::table('subnets')->insertGetId(['name' => 'char rest', 'network_address' => '10.250.11.0', 'netmask' => '255.255.255.0']);
        $ip = (int) DB::table('ip_addresses')->insertGetId(['iface_id' => $iface, 'subnet_id' => $subnet, 'ip_address' => '10.250.11.7']);
        DB::table('messages_ip_addresses')->insert(['message_id' => (int) DB::table('messages')->value('id'), 'ip_address_id' => $ip, 'datetime' => now()]);

        return ['member' => $id, 'account' => $acc, 'mf' => $mf, 'ip' => $ip];
    }

    public function test_obnoveni_preruseni_dnes(): void
    {
        $x = $this->interrupted(1000);
        $this->as()->post(route('members.restore-interrupt', $x['member']))->assertRedirect(route('members.show', $x['member']))
            ->assertSessionHas('success', 'Členství obnoveno dnes. Poplatek strhnut k ' . now()->toDateString() . ', přesměrování smazáno.');

        $m = DB::table('members')->where('id', $x['member'])->first();
        $this->assertSame(['9999-12-31', 0, 0, null, 0],
            [$m->leaving_date, (int) $m->locked, (int) $m->payment_blocked, $m->payment_blocked_since, (int) $m->pending_termination]);
        $this->assertSame(now()->subDay()->toDateString(), DB::table('members_fees')->where('id', $x['mf'])->value('deactivation_date'));
        $this->assertSame(0, (int) DB::table('membership_interrupts')->where('members_fee_id', $x['mf'])->value('end_after_interrupt_end'));
        $this->assertFalse(DB::table('messages_ip_addresses')->where('ip_address_id', $x['ip'])->exists());

        $t = DB::table('transfers')->where('origin_id', $x['account'])->where('type', 1)->first();
        $this->assertSame([$this->adminId, null], [(int) $t->user_id, $t->member_id]);
        $this->assertTrue(DB::table('audit_logs')->where('auditable_type', 'transfers')->where('action', 'created')
            ->where('new_values', 'like', '%"member_id":' . $x['member'] . ',%')->exists());
        $audit = DB::table('audit_logs')->where('auditable_type', 'members')->where('auditable_id', $x['member'])->where('action', 'updated')->first();
        $this->assertSame(['note' => 'obnovení přerušení členství', 'effective' => now()->toDateString()], json_decode($audit->new_values, true));
    }

    public function test_obnoveni_preruseni_k_datu(): void
    {
        $x = $this->interrupted(1000);
        $date = now()->addDays(10)->toDateString();
        $this->as()->post(route('members.restore-interrupt', $x['member']), ['effective_date' => $date])
            ->assertSessionHas('success', "Členství obnoveno k {$date}. Poplatek strhnut k {$date}, přesměrování smazáno.");
        $this->assertSame(now()->addDays(9)->toDateString(), DB::table('members_fees')->where('id', $x['mf'])->value('deactivation_date'));
        $this->assertSame($date . ' 00:00:00', DB::table('transfers')->where('origin_id', $x['account'])->where('type', 1)->value('datetime'));
    }

    public function test_obnoveni_preruseni_bez_kreditu_hlaska(): void
    {
        $x = $this->interrupted(10);
        $this->as()->post(route('members.restore-interrupt', $x['member']))->assertSessionHas('success',
            'Členství obnoveno dnes. Poplatek NEstrhnut — nedostatek kreditu, nastaven payment_blocked. Doplatek proběhne po další příchozí platbě.');
        $m = DB::table('members')->where('id', $x['member'])->first();
        $this->assertSame(1, (int) $m->payment_blocked);
        $this->assertSame(now()->toDateString(), $m->payment_blocked_since, 'flag se nejdřív vynuluje, pak nastaví k dnešku');
    }

    public function test_obnoveni_preruseni_nulovy_poplatek_a_dvakrat(): void
    {
        $x = $this->interrupted(1000);
        DB::table('members_fees')->insert(['fee_id' => $this->fee(0), 'member_id' => $x['member'], 'activation_date' => '2020-01-01', 'deactivation_date' => '9999-12-31', 'priority' => 1, 'comment' => 'osvobozen']);
        $this->as()->post(route('members.restore-interrupt', $x['member']))->assertSessionHas('success',
            'Členství obnoveno dnes. Poplatek nestrhnut (nulový tarif nebo už existuje), přesměrování smazáno.');
        $this->assertFalse(DB::table('transfers')->where('origin_id', $x['account'])->exists());
    }

    public function test_obnoveni_preruseni_uz_strzeno_dnes_noop(): void
    {
        $x = $this->interrupted(1000);
        $org = (int) DB::table('accounts')->where('member_id', 1)->where('account_attribute_id', 221101)->value('id');
        DB::table('transfers')->insert(['origin_id' => $x['account'], 'destination_id' => $org, 'type' => 1, 'amount' => 300,
            'datetime' => now()->toDateString(), 'creation_datetime' => now(), 'text' => 'char']);
        $this->as()->post(route('members.restore-interrupt', $x['member']))->assertSessionHas('success',
            'Členství obnoveno dnes. Poplatek nestrhnut (nulový tarif nebo už existuje), přesměrování smazáno.');
        $this->assertSame(1, DB::table('transfers')->where('origin_id', $x['account'])->count());
    }

    public function test_obnoveni_bez_preruseni_chyba(): void
    {
        $id = $this->member(2);
        $this->as()->post(route('members.restore-interrupt', $id))->assertRedirect(route('members.index'))
            ->assertSessionHas('error', 'Tento člen nemá aktivní přerušení členství.');
    }

    public function test_obnoveni_preruseni_datum_mimo_rozsah(): void
    {
        $x = $this->interrupted(1000);
        $this->as()->post(route('members.restore-interrupt', $x['member']), ['effective_date' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors(['effective_date' => 'Datum obnovy nesmí být v minulosti.']);
        $this->as()->post(route('members.restore-interrupt', $x['member']), ['effective_date' => now()->addDays(61)->toDateString()])
            ->assertSessionHasErrors('effective_date');
        $this->assertSame(1, (int) DB::table('members')->where('id', $x['member'])->value('locked'));
    }

    // ── formulář Ukončit (předvyplnění) ──────────────────────────────────────

    /** Účet pro vratku: poslední bankovní platba na kredit člena, jinak nejnovější bank_accounts člena. */
    public function test_formular_ukoncit_ucet_pro_vratku_a_dnesni_srazka(): void
    {
        $id  = $this->member(2);
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => 500, 'name' => 'char']);
        $org = (int) DB::table('accounts')->where('member_id', 1)->where('account_attribute_id', 221101)->value('id');
        $form = fn () => $this->as()->get(route('members.end-membership', $id))->assertOk();

        $form()->assertViewHas('refundAccount', '')->assertViewHas('todayDeducted', false)->assertViewHas('balance', '500.00');

        DB::table('bank_accounts')->insert(['name' => 'char', 'member_id' => $id, 'account_nr' => '111', 'bank_nr' => '0100']);
        DB::table('bank_accounts')->insert(['name' => 'char', 'member_id' => $id, 'account_nr' => ' 222 ', 'bank_nr' => '0300']);
        $form()->assertViewHas('refundAccount', '222/0300');

        // platba: bankovní převod (t1) → přeposlání na kredit člena (t2, previous_transfer_id = t1)
        $payer = (int) DB::table('bank_accounts')->insertGetId(['name' => 'char', 'member_id' => null, 'account_nr' => '333', 'bank_nr' => '0800']);
        $t1 = (int) DB::table('transfers')->insertGetId(['origin_id' => $org, 'destination_id' => $org, 'type' => 0, 'amount' => 100, 'datetime' => '2026-01-05', 'creation_datetime' => '2026-01-05', 'text' => 'char']);
        DB::table('transfers')->insert(['origin_id' => $org, 'destination_id' => $acc, 'previous_transfer_id' => $t1, 'type' => 0, 'amount' => 100, 'datetime' => '2026-01-05', 'creation_datetime' => '2026-01-05', 'text' => 'char']);
        DB::table('bank_transfers')->insert(['origin_id' => $payer, 'transfer_id' => $t1]);
        $form()->assertViewHas('refundAccount', '333/0800');

        DB::table('transfers')->insert(['origin_id' => $acc, 'destination_id' => $org, 'type' => 1, 'amount' => 300, 'datetime' => now()->toDateString(), 'creation_datetime' => now(), 'text' => 'char']);
        $form()->assertViewHas('todayDeducted', true)->assertViewHas('monthlyFee', 300.0)->assertViewHas('deductDay', (int) Setting::get('deduct_day', 26));
    }
}
