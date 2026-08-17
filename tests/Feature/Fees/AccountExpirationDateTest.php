<?php

namespace Tests\Feature\Fees;

use App\Models\Account;
use App\Services\RegularFeeResolver;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Regrese: Account::getExpirationDate („Zaplaceno do") musí počítat měsíční
 * poplatek SHODNĚ s reálnou srážkou (RegularFeeResolver: individuální → tarif →
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
            if (RegularFeeResolver::individualFee((int) $m->id, $today) === null) {
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
        $dLow = Account::find($accountId)->getExpirationDate();

        // Vysoký tarif → míň měsíců → dřívější expirace.
        DB::table('speed_classes')->where('id', $scId)->update(['price' => 1000]);
        $dHigh = Account::find($accountId)->getExpirationDate();

        $this->assertNotNull($dLow);
        $this->assertNotNull($dHigh);
        // Dluhy/entrance jsou v obou bězích stejné → rozdíl izoluje efekt tarifu.
        // Bug (ignorovaný tarif, konstantní default) → obě data stejná. Fix → rozdílná.
        $this->assertGreaterThan(
            $dHigh, $dLow,
            'getExpirationDate musí zohlednit cenu tarifu — nižší poplatek = pozdější „Zaplaceno do"'
        );
    }

    public function test_getexpirationdate_respektuje_individualni_pred_tarifem(): void
    {
        [$member, $scId, $accountId] = $this->tariffMember();
        DB::table('accounts')->where('id', $accountId)->update(['balance' => 1200]);
        DB::table('speed_classes')->where('id', $scId)->update(['price' => 1000]); // drahý tarif

        $dTariff = Account::find($accountId)->getExpirationDate();

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

        $dIndividual = Account::find($accountId)->getExpirationDate();

        $this->assertGreaterThan(
            $dTariff, $dIndividual,
            'individuální poplatek (members_fees) musí mít přednost před tarifem'
        );
    }
}
