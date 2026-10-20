<?php

namespace Tests\Feature\Fees;

use App\Models\Account;
use App\Domains\Billing\Fees\FeePolicy;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Regrese: Billing\Fees\PaidUntil („Zaplaceno do") musí počítat měsíční
 * poplatek SHODNĚ s reálnou srážkou (FeePolicy::regularFee: individuální → tarif →
 * default dle typu). Dřív bral jen members_fees(member) → fallback member_id=1
 * default (320), takže u členů s tarifním poplatkem ukazoval špatné datum.
 * Bug objeven 2026-08-17 (viz task_getexpirationdate_fee_mismatch).
 */
class AccountExpirationDateTest extends DatabaseTestCase
{
    /** Člen s cenou tarifu a bez individuálního 'regular member fee'. Vrací [id, speed_class_id, credit_account]. */
    private function tariffMember(): array
    {
        $today = now()->toDateString();
        $rows = DB::table('members as m')
            ->join('speed_classes as sc', 'sc.id', '=', 'm.speed_class_id')
            ->join('accounts as a', function ($j) {
                $j->on('a.member_id', '=', 'm.id')->where('a.account_attribute_id', '=', 221100);
            })
            ->where('m.id', '>', 1)->whereNotNull('sc.price')
            ->orderBy('m.id')->limit(200)
            ->get(['m.id', 'm.speed_class_id', 'a.id as account_id']);

        foreach ($rows as $m) {
            if (app(FeePolicy::class)->individualFee((int) $m->id, $today) === null) {
                return [(int) $m->id, (int) $m->speed_class_id, (int) $m->account_id];
            }
        }
        $this->markTestSkipped('nenašel jsem člena s tarifem, kreditním účtem a bez individuálního poplatku');
    }

    public function test_getexpirationdate_reaguje_na_cenu_tarifu(): void
    {
        [$member, $scId, $accountId] = $this->tariffMember();

        // Kontrolovaný kladný zůstatek, ať je rozdíl v počtu pokrytých měsíců výrazný.
        DB::table('accounts')->where('id', $accountId)->update(['balance' => 1200]);

        // Nízký tarif → víc pokrytých měsíců → pozdější expirace.
        DB::table('speed_classes')->where('id', $scId)->update(['price' => 100]);
        $dLow = app(\App\Domains\Billing\Fees\PaidUntil::class)->for(Account::find($accountId));

        // Vysoký tarif → míň měsíců → dřívější expirace.
        DB::table('speed_classes')->where('id', $scId)->update(['price' => 1000]);
        $dHigh = app(\App\Domains\Billing\Fees\PaidUntil::class)->for(Account::find($accountId));

        $this->assertNotNull($dLow);
        $this->assertNotNull($dHigh);
        // Dluhy/entrance jsou v obou bězích stejné → rozdíl izoluje efekt tarifu.
        // Bug (ignorovaný tarif, konstantní default) → obě data stejná. Fix → rozdílná.
        $this->assertGreaterThan(
            $dHigh, $dLow,
            'PaidUntil musí zohlednit cenu tarifu — nižší poplatek = pozdější „Zaplaceno do"'
        );
    }

    public function test_getexpirationdate_respektuje_individualni_pred_tarifem(): void
    {
        [$member, $scId, $accountId] = $this->tariffMember();
        DB::table('accounts')->where('id', $accountId)->update(['balance' => 1200]);
        DB::table('speed_classes')->where('id', $scId)->update(['price' => 1000]); // drahý tarif

        $dTariff = app(\App\Domains\Billing\Fees\PaidUntil::class)->for(Account::find($accountId));

        // Individuální poplatek 100 (levnější) má přednost před tarifem 1000 → pozdější datum.
        $feeId = DB::table('fees')->insertGetId([
            'readonly' => 0, 'fee' => 100, 'from' => '2020-01-01', 'to' => '9999-12-31',
            'type_id'  => 35, 'name' => 'Test individuální', 'special_type_id' => null,
        ]);
        DB::table('members_fees')->insert([
            'fee_id' => $feeId, 'member_id' => $member,
            'activation_date' => '2020-01-01', 'deactivation_date' => '9999-12-31',
            'priority' => 1, 'comment' => 'test',
        ]);

        $dIndividual = app(\App\Domains\Billing\Fees\PaidUntil::class)->for(Account::find($accountId));

        $this->assertGreaterThan(
            $dTariff, $dIndividual,
            'individuální poplatek (members_fees) musí mít přednost před tarifem'
        );
    }

    /**
     * Člen bez strhávaného poplatku (bývalý zákazník bez tarifu/individuálního,
     * typ bez základního poplatku) → „Zaplaceno do" nemá smysl → null („—").
     * Dřív +50 let (incident .54, člen 6235: 31.08.2076).
     */
    public function test_bez_poplatku_vrati_null(): void
    {
        $apId = (int) \Illuminate\Support\Facades\DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        \App\Models\Setting::set('default_fee_member_type_16', '');
        $id = (int) \Illuminate\Support\Facades\DB::table('members')->insertGetId([
            'name' => 'Char Exp', 'type' => 16, 'registration' => 1, 'locked' => 1, 'address_point_id' => $apId,
            'entrance_date' => '2015-11-25', 'leaving_date' => '2026-09-14', 'speed_class_id' => null,
        ]);
        $accId = (int) \Illuminate\Support\Facades\DB::table('accounts')->insertGetId([
            'member_id' => $id, 'account_attribute_id' => 221100, 'balance' => 0, 'name' => 'char',
        ]);

        $this->assertNull(app(\App\Domains\Billing\Fees\PaidUntil::class)->for(\App\Models\Account::find($accId)));
    }

    /** D4: dodatečná služba se do „Zaplaceno do" počítá jako tarif (strhává se měsíčně taky). */
    public function test_priplatky_se_pocitaji(): void
    {
        $db = \Illuminate\Support\Facades\DB::class;
        $apId = (int) $db::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $mk = function (float $tarif) use ($db, $apId): array {
            $sc = (int) $db::table('speed_classes')->insertGetId(['name' => 'char ' . uniqid(), 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => $tarif]);
            $id = (int) $db::table('members')->insertGetId([
                'name' => 'Char Exp', 'type' => 2, 'registration' => 1, 'locked' => 0, 'address_point_id' => $apId,
                'entrance_date' => '2026-01-10', 'leaving_date' => '9999-12-31', 'speed_class_id' => $sc,
            ]);
            $acc = (int) $db::table('accounts')->insertGetId(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => 2000, 'name' => 'char']);
            return [$id, $acc];
        };
        [$withSvc, $accSvc] = $mk(300);
        $feeId = (int) $db::table('fees')->insertGetId(['readonly' => 0, 'fee' => 100, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => 39, 'name' => 'char svc', 'special_type_id' => null]);
        $db::table('members_fees')->insert(['fee_id' => $feeId, 'member_id' => $withSvc, 'activation_date' => '2000-01-01', 'deactivation_date' => '9999-12-31', 'priority' => 1, 'comment' => 'char']);
        [, $acc400] = $mk(400);
        [, $acc300] = $mk(300);

        $svc = app(\App\Domains\Billing\Fees\PaidUntil::class)->for(\App\Models\Account::find($accSvc));
        $this->assertSame(app(\App\Domains\Billing\Fees\PaidUntil::class)->for(\App\Models\Account::find($acc400)), $svc, 'tarif 300 + služba 100 = jako tarif 400');
        $this->assertLessThan(app(\App\Domains\Billing\Fees\PaidUntil::class)->for(\App\Models\Account::find($acc300)), $svc, 'se službou kredit vydrží kratší dobu');
    }

    /** D1/D4: čekající (18) se neúčtuje → „—". */
    public function test_cekajici_bez_data(): void
    {
        $apId = (int) \Illuminate\Support\Facades\DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $id = (int) \Illuminate\Support\Facades\DB::table('members')->insertGetId([
            'name' => 'Char Exp', 'type' => 18, 'registration' => 0, 'locked' => 0, 'address_point_id' => $apId,
            'entrance_date' => '2026-01-10', 'leaving_date' => '9999-12-31',
        ]);
        $acc = (int) \Illuminate\Support\Facades\DB::table('accounts')->insertGetId(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => 1000, 'name' => 'char']);
        $this->assertNull(app(\App\Domains\Billing\Fees\PaidUntil::class)->for(\App\Models\Account::find($acc)));
    }
}
