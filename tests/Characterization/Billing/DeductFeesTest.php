<?php

namespace Tests\Characterization\Billing;

use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace `fees:deduct` (App\Console\Commands\DeductFees) — měsíční
 * strhávání poplatků, před přesunem do domény Billing. Spouští CELÝ příkaz
 * (`--force --member=<syntetický člen>`), takže zachytí i pořadí sekcí,
 * přepočet účtu organizace a audit.
 *
 * Zachycuje SOUČASNÉ chování včetně podivností (*_quirk) — změna chování =
 * samostatný commit, který test vědomě upraví.
 */
class DeductFeesTest extends DatabaseTestCase
{
    private const DATE   = '2099-06-26';
    private const CREDIT = 221100;

    private int $apId;
    private int $type;
    private int $orgOperating;
    private int $orgInfrastructure;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $this->type = (int) DB::table('devices')->value('type');
        $this->orgOperating = (int) DB::table('accounts')->where('member_id', 1)->where('account_attribute_id', 221101)->value('id');
        $this->orgInfrastructure = (int) DB::table('accounts')->where('member_id', 1)->where('account_attribute_id', 221102)->value('id');
        if (!$this->apId || !$this->orgOperating || !$this->orgInfrastructure) {
            $this->markTestSkipped('chybí adresní bod / účty organizace');
        }

        // Výchozí poplatky dle typu: řádný (2) 300, zákazník (90) 250.
        Setting::set('default_fee_member_type_2', $this->fee(300, 35));
        Setting::set('default_fee_member_type_90', $this->fee(250, 35));
        Setting::set('finance_enabled', 1);
        Setting::set('deduct_fees_automatically_enabled', 1);
        Setting::set('deduct_day', 26);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function fee(float $amount, int $typeId): int
    {
        return (int) DB::table('fees')->insertGetId([
            'readonly' => 0, 'fee' => $amount, 'from' => '2000-01-01', 'to' => '9999-12-31',
            'type_id' => $typeId, 'name' => 'char fee', 'special_type_id' => null,
        ]);
    }

    /** @return array{member:int, account:int} */
    private function member(int $type = 2, float $balance = 10000, array $m = []): array
    {
        $id = (int) DB::table('members')->insertGetId($m + [
            'name' => 'Char Billing', 'type' => $type, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $this->apId, 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31',
            'payment_blocked' => 0,
        ]);
        $acc = (int) DB::table('accounts')->insertGetId([
            'member_id' => $id, 'account_attribute_id' => self::CREDIT, 'balance' => $balance, 'name' => 'char',
        ]);
        return ['member' => $id, 'account' => $acc];
    }

    private function individualFee(int $member, float $amount, int $priority = 1, int $typeId = 35): void
    {
        DB::table('members_fees')->insert([
            'fee_id' => $this->fee($amount, $typeId), 'member_id' => $member,
            'activation_date' => '2000-01-01', 'deactivation_date' => '9999-12-31', 'priority' => $priority, 'comment' => 'char',
        ]);
    }

    private function speedClass(?float $price): int
    {
        return (int) DB::table('speed_classes')->insertGetId([
            'name' => 'char ' . uniqid(), 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => $price,
        ]);
    }

    private function device(int $member, float $price, float $rate): void
    {
        $uid = (int) DB::table('users')->where('member_id', $member)->value('id') ?: (int) DB::table('users')->insertGetId([
            'member_id' => $member, 'login' => 'char_bill_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'xxxxxxxx', 'settings' => '', 'name' => 'Char', 'surname' => 'Bill', 'comment' => '',
        ]);
        DB::table('devices')->insert(['name' => 'char-dev', 'type' => $this->type, 'user_id' => $uid, 'price' => $price, 'payment_rate' => $rate]);
    }

    private function paid(int $account, int $type, float $amount, string $date = '2099-01-26'): void
    {
        DB::table('transfers')->insert([
            'origin_id' => $account, 'destination_id' => $this->orgOperating, 'type' => $type,
            'amount' => $amount, 'datetime' => $date, 'creation_datetime' => $date, 'text' => 'char',
        ]);
    }

    private function deduct(int $member, array $opts = ['--force' => true]): string
    {
        Artisan::call('fees:deduct', $opts + ['--date' => self::DATE, '--member' => $member]);
        return Artisan::output();
    }

    /** @return array<int, object> transfery účtu k DATE podle typu */
    private function transfers(int $account): array
    {
        return DB::table('transfers')->where('origin_id', $account)->where('datetime', self::DATE)
            ->orderBy('type')->get()->keyBy('type')->all();
    }

    private function balance(int $account): float
    {
        return (float) DB::table('accounts')->where('id', $account)->value('balance');
    }

    private function memberRow(int $member): object
    {
        return DB::table('members')->where('id', $member)->first();
    }

    // ── členský poplatek (type 1) ────────────────────────────────────────────

    public function test_radny_clen_vychozi_poplatek(): void
    {
        $x = $this->member(2, 1000);
        $out = $this->deduct($x['member']);

        $t = $this->transfers($x['account']);
        $this->assertSame([1], array_keys($t));
        $this->assertEqualsWithDelta(300, $t[1]->amount, 0.001);
        $this->assertSame($this->orgOperating, (int) $t[1]->destination_id);
        $this->assertSame('Automatická srážka členského příspěvku', $t[1]->text);
        $this->assertSame(self::DATE . ' 00:00:00', $t[1]->datetime);
        $this->assertNull($t[1]->member_id);
        $this->assertNull($t[1]->user_id);
        $this->assertEqualsWithDelta(700, $this->balance($x['account']), 0.001);
        $this->assertStringContainsString('Member fees: 1 deductions, 0 skipped', $out);
        $this->assertStringContainsString('Done. Total deductions: 1', $out);
    }

    public function test_zakaznik_vychozi_poplatek(): void
    {
        $x = $this->member(90, 1000);
        $this->deduct($x['member']);
        $this->assertEqualsWithDelta(250, $this->transfers($x['account'])[1]->amount, 0.001);
    }

    public function test_tarif_ma_prednost_pred_vychozim(): void
    {
        $x = $this->member(2, 1000, ['speed_class_id' => $this->speedClass(199)]);
        $this->deduct($x['member']);
        $this->assertEqualsWithDelta(199, $this->transfers($x['account'])[1]->amount, 0.001);
    }

    public function test_tarif_bez_ceny_propadne_na_vychozi(): void
    {
        $x = $this->member(2, 1000, ['speed_class_id' => $this->speedClass(null)]);
        $this->deduct($x['member']);
        $this->assertEqualsWithDelta(300, $this->transfers($x['account'])[1]->amount, 0.001);
    }

    public function test_individualni_ma_prednost_nejnizsi_priorita_vyhrava(): void
    {
        $x = $this->member(2, 1000, ['speed_class_id' => $this->speedClass(199)]);
        $this->individualFee($x['member'], 150, 2);
        $this->individualFee($x['member'], 120, 1);
        $this->deduct($x['member']);
        $this->assertEqualsWithDelta(120, $this->transfers($x['account'])[1]->amount, 0.001);
    }

    public function test_individualni_nula_osvobodi(): void
    {
        $x = $this->member(2, 0);
        $this->individualFee($x['member'], 0);
        $this->deduct($x['member']);
        $this->assertSame([], $this->transfers($x['account']));
        $this->assertSame(0, (int) $this->memberRow($x['member'])->payment_blocked);
    }

    public function test_nedostatek_kreditu_zablokuje_bez_srazky(): void
    {
        $x = $this->member(2, 299.99);
        $out = $this->deduct($x['member']);

        $this->assertSame([], $this->transfers($x['account']));
        $this->assertEqualsWithDelta(299.99, $this->balance($x['account']), 0.001);
        $m = $this->memberRow($x['member']);
        $this->assertSame(1, (int) $m->payment_blocked);
        $this->assertSame(self::DATE, $m->payment_blocked_since);
        $this->assertStringContainsString('Member fees: 0 deductions, 1 skipped', $out);
    }

    /** Datum vzniku dluhu se nepřepisuje (auto-ukončení počítá od prvního). */
    public function test_existujici_blokace_drzi_puvodni_datum(): void
    {
        $x = $this->member(2, 0, ['payment_blocked' => 1, 'payment_blocked_since' => '2099-03-26']);
        $this->deduct($x['member']);
        $this->assertSame('2099-03-26', $this->memberRow($x['member'])->payment_blocked_since);
    }

    public function test_presne_pokryty_kredit_strhne_a_zrusi_blokaci(): void
    {
        $x = $this->member(2, 300, ['payment_blocked' => 1, 'payment_blocked_since' => '2099-03-26']);
        $out = $this->deduct($x['member']);

        $this->assertArrayHasKey(1, $this->transfers($x['account']));
        $this->assertEqualsWithDelta(0, $this->balance($x['account']), 0.001);
        $m = $this->memberRow($x['member']);
        $this->assertSame(0, (int) $m->payment_blocked);
        $this->assertNull($m->payment_blocked_since);
        $this->assertStringContainsString('1 flag-reset', $out);
    }

    public function test_idempotence_druhy_beh_nic(): void
    {
        $x = $this->member(2, 1000);
        $this->deduct($x['member']);
        $out = $this->deduct($x['member']);

        $this->assertSame(1, DB::table('transfers')->where('origin_id', $x['account'])->where('datetime', self::DATE)->count());
        $this->assertEqualsWithDelta(700, $this->balance($x['account']), 0.001);
        $this->assertStringContainsString('Done. Total deductions: 0', $out);
    }

    /** NULL entrance_date = poplatek se nestrhne (proto ho schválení vždy nastavuje). */
    public function test_bez_data_vstupu_nic(): void
    {
        $x = $this->member(2, 1000, ['entrance_date' => null]);
        $this->deduct($x['member']);
        $this->assertSame([], $this->transfers($x['account']));
    }

    /** Vstup v den srážky → ještě se nestrhává (striktní <). */
    public function test_vstup_v_den_srazky_nic(): void
    {
        $x = $this->member(2, 1000, ['entrance_date' => self::DATE]);
        $this->deduct($x['member']);
        $this->assertSame([], $this->transfers($x['account']));
    }

    public function test_odchod_pred_srazkou_nic_odchod_po_strhne(): void
    {
        $gone = $this->member(2, 1000, ['leaving_date' => self::DATE]);
        $this->deduct($gone['member']);
        $this->assertSame([], $this->transfers($gone['account']));

        $leaving = $this->member(2, 1000, ['leaving_date' => '2099-06-27']);
        $this->deduct($leaving['member']);
        $this->assertArrayHasKey(1, $this->transfers($leaving['account']));
    }

    /** Typ bez výchozího poplatku (např. čekatel 17) bez tarifu → nic. */
    public function test_typ_bez_vychoziho_bez_tarifu_nic(): void
    {
        $x = $this->member(17, 1000);
        $this->deduct($x['member']);
        $this->assertSame([], $this->transfers($x['account']));
    }

    /**
     * Žadatel / čekající (1, 17, 18) se neúčtuje vůbec — ani tarif, ani služby
     * (dřív quirk: tarif se strhl i čekateli). FeePolicy::NOT_BILLED_TYPES.
     */
    public function test_cekatel_s_tarifem_a_sluzbou_nezaplati(): void
    {
        foreach ([1, 17, 18] as $type) {
            $x = $this->member($type, 1000, ['speed_class_id' => $this->speedClass(199)]);
            $this->individualFee($x['member'], 50, 1, 39);
            $this->deduct($x['member']);
            $this->assertSame([], $this->transfers($x['account']), "typ {$type}");
            $this->assertSame(0, (int) $this->memberRow($x['member'])->payment_blocked);
        }
    }

    // ── dodatečné služby (type 6) ────────────────────────────────────────────

    public function test_sluzby_po_clenskem_kredit_na_clensky_prednostne(): void
    {
        $x = $this->member(2, 350);
        $this->individualFee($x['member'], 100, 1, 39); // dodatečná služba
        $out = $this->deduct($x['member']);

        $t = $this->transfers($x['account']);
        $this->assertSame([1], array_keys($t), 'na službu už kredit nezbyl');
        $this->assertSame(1, (int) $this->memberRow($x['member'])->payment_blocked, 'služba nastaví blokaci');
        $this->assertStringContainsString('Additional services: 0 deductions, 1 skipped', $out);
    }

    public function test_sluzby_strhnou_soucet_samostatne(): void
    {
        $x = $this->member(2, 1000);
        $this->individualFee($x['member'], 100, 1, 39);
        $this->individualFee($x['member'], 50, 2, 39);
        $this->deduct($x['member']);

        $t = $this->transfers($x['account']);
        $this->assertEqualsWithDelta(150, $t[6]->amount, 0.001);
        $this->assertSame('Automatická srážka – dodatečné služby', $t[6]->text);
        $this->assertEqualsWithDelta(550, $this->balance($x['account']), 0.001);
    }

    // ── vstupní poplatek (type 2) ────────────────────────────────────────────

    public function test_vstupni_splatka_omezena_zbytkem_dluhu(): void
    {
        $x = $this->member(2, 1000, ['entrance_fee' => 1000, 'debt_payment_rate' => 300]);
        $this->individualFee($x['member'], 0); // bez členského
        $this->paid($x['account'], 2, 900);
        $this->deduct($x['member']);

        $t = $this->transfers($x['account']);
        $this->assertEqualsWithDelta(100, $t[2]->amount, 0.001);
        $this->assertSame($this->orgInfrastructure, (int) $t[2]->destination_id);
        $this->assertSame('Automatická srážka vstupního poplatku', $t[2]->text);
    }

    public function test_vstupni_splatka_dle_sazby(): void
    {
        $x = $this->member(2, 1000, ['entrance_fee' => 1000, 'debt_payment_rate' => 300]);
        $this->individualFee($x['member'], 0);
        $this->deduct($x['member']);
        $this->assertEqualsWithDelta(300, $this->transfers($x['account'])[2]->amount, 0.001);
    }

    public function test_vstupni_nedostatek_kreditu_preskoci_bez_blokace(): void
    {
        $x = $this->member(2, 299, ['entrance_fee' => 1000, 'debt_payment_rate' => 300]);
        $this->individualFee($x['member'], 0);
        $out = $this->deduct($x['member']);

        $this->assertSame([], $this->transfers($x['account']));
        $this->assertSame(0, (int) $this->memberRow($x['member'])->payment_blocked);
        $this->assertStringContainsString('Entrance fees: 0 deductions, 1 skipped', $out);
    }

    public function test_vstupni_cekatel_vynechan(): void
    {
        $x = $this->member(17, 1000, ['entrance_fee' => 1000, 'debt_payment_rate' => 300]);
        $this->deduct($x['member']);
        $this->assertArrayNotHasKey(2, $this->transfers($x['account']));
    }

    // ── zařízení (type 5) ────────────────────────────────────────────────────

    public function test_zarizeni_splatka(): void
    {
        $x = $this->member(2, 1000);
        $this->individualFee($x['member'], 0);
        $this->device($x['member'], 1000, 400);
        $this->deduct($x['member']);

        $t = $this->transfers($x['account']);
        $this->assertEqualsWithDelta(400, $t[5]->amount, 0.001);
        $this->assertSame($this->orgOperating, (int) $t[5]->destination_id);
        $this->assertSame('Automatická srážka poplatku za zařízení', $t[5]->text);
    }

    /**
     * Quirk: víc zpoplatněných zařízení → JOIN násobí zaplacené splátky
     * (SUM přes každé zařízení) a bere MIN(price), MIN(payment_rate).
     * Zaplaceno 100 → započteno 200; dluh = 600 − 200 = 400; splátka = MIN(rate) 100.
     */
    public function test_vice_zarizeni_quirk(): void
    {
        $x = $this->member(2, 1000);
        $this->individualFee($x['member'], 0);
        $this->device($x['member'], 1000, 400);
        $this->device($x['member'], 600, 100);
        $this->paid($x['account'], 5, 100);
        $this->deduct($x['member']);

        $this->assertEqualsWithDelta(100, $this->transfers($x['account'])[5]->amount, 0.001);
    }

    /** Quirk: zařízení se strhává i bývalému členovi (žádný filtr typu/odchodu). */
    public function test_zarizeni_byvalemu_clenovi_quirk(): void
    {
        $x = $this->member(15, 1000, ['leaving_date' => '2099-01-01']);
        $this->device($x['member'], 1000, 400);
        $this->deduct($x['member']);

        $t = $this->transfers($x['account']);
        $this->assertSame([5], array_keys($t));
    }

    // ── celek ────────────────────────────────────────────────────────────────

    public function test_ucet_organizace_prepocten_ze_vsech_transferu(): void
    {
        $x = $this->member(2, 1000);
        $this->deduct($x['member']);

        $in  = (float) DB::table('transfers')->where('destination_id', $this->orgOperating)->sum('amount');
        $out = (float) DB::table('transfers')->where('origin_id', $this->orgOperating)->sum('amount');
        $this->assertEqualsWithDelta($in - $out, (float) DB::table('accounts')->where('id', $this->orgOperating)->value('balance'), 0.01);
    }

    public function test_audit_souhrn_jen_kdyz_neco_strhl(): void
    {
        $before = DB::table('audit_logs')->where('action', 'fee_deduction')->count();
        $none = $this->member(2, 0);
        $this->deduct($none['member']);
        $this->assertSame($before, DB::table('audit_logs')->where('action', 'fee_deduction')->count());

        $x = $this->member(2, 1000);
        $this->individualFee($x['member'], 100, 1, 39);
        $this->deduct($x['member']);
        $log = DB::table('audit_logs')->where('action', 'fee_deduction')->orderByDesc('id')->first();
        $this->assertSame('transfers', $log->auditable_type);
        $this->assertNull($log->auditable_id);
        $this->assertSame(['date' => self::DATE, 'deductions' => 2], json_decode($log->new_values, true));
    }

    public function test_bez_force_respektuje_nastaveni_a_den(): void
    {
        $x = $this->member(2, 1000);

        Setting::set('finance_enabled', 0);
        $this->assertStringContainsString('Finance disabled, skipping.', $this->deduct($x['member'], []));

        Setting::set('finance_enabled', 1);
        Setting::set('deduct_fees_automatically_enabled', 0);
        $this->assertStringContainsString('Automatic fee deduction disabled, skipping.', $this->deduct($x['member'], []));

        Setting::set('deduct_fees_automatically_enabled', 1);
        Setting::set('deduct_day', 25);
        $this->assertStringContainsString('Today is day 26, deduct_day is 25 — skipping.', $this->deduct($x['member'], []));
        $this->assertSame([], $this->transfers($x['account']));

        Setting::set('deduct_day', 26);
        $this->deduct($x['member'], []);
        $this->assertArrayHasKey(1, $this->transfers($x['account']));
    }

    /** deduct_day větší než počet dní v měsíci → poslední den měsíce. */
    public function test_den_srazky_za_koncem_mesice(): void
    {
        $x = $this->member(2, 1000);
        Setting::set('deduct_day', 31);
        Artisan::call('fees:deduct', ['--date' => '2099-06-30', '--member' => $x['member']]);
        $this->assertSame(1, DB::table('transfers')->where('origin_id', $x['account'])->where('datetime', '2099-06-30')->count());
    }

    /** Selhání sekce (rollback) → exit 1 + chyba; ostatní sekce běží dál. */
    public function test_selhani_sekce_vrati_chybovy_kod(): void
    {
        $x = $this->member(2, 1000);
        $this->partialMock(\App\Domains\Billing\Repositories\LedgerRepository::class)
            ->shouldReceive('postFee')->andThrow(new \RuntimeException('boom'));

        $code = Artisan::call('fees:deduct', ['--force' => true, '--date' => self::DATE, '--member' => $x['member']]);
        $out  = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Member fee deduction failed: boom', $out);
        $this->assertStringContainsString('Srážka selhala v sekcích: member fees', $out);
        $this->assertSame([], $this->transfers($x['account']));
        $this->assertEqualsWithDelta(1000, $this->balance($x['account']), 0.001);
    }

    /** Bývalý (15/16) s budoucím datem odchodu (starý stav ukončení) — nestrhne ani nezablokuje. */
    public function test_byvaly_s_budoucim_odchodem_nic(): void
    {
        $x = $this->member(16, 0, ['leaving_date' => '2099-07-14']);
        $this->individualFee($x['member'], 100, 1, 39);
        $this->deduct($x['member']);

        $this->assertSame([], $this->transfers($x['account']));
        $this->assertSame(0, (int) $this->memberRow($x['member'])->payment_blocked);
    }
}
