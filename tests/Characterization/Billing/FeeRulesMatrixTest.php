<?php

namespace Tests\Characterization\Billing;

use App\Models\Setting;
use App\Services\AllowedSubnetFeesResolver;
use App\Services\PaymentQrService;
use App\Domains\Billing\Fees\FeePolicy;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace: KOLIK má člen platit — podle každého místa, které to počítá
 * (před sjednocením do Billing FeePolicy). Pravidlo „individuální → tarif →
 * základní dle typu" je dnes implementované víckrát:
 *
 *   deduct   = fees:deduct (hromadné SQL v LedgerRepository + DeductFeesAction)
 *   resolver = FeePolicy::regularFee — používá ho dohnání po platbě
 *              (ChargeOverdueFeesAction), ruční srážka po obnovení přerušení
 *              (MemberController) a „Zaplaceno do" (Account)
 *   qr       = PaymentQrService (resolver + fallback na tarif sdružení)
 *
 * Řádky označené ROZDÍL jsou místa, kde se dnes počítá různě — kandidáti na
 * vědomé rozhodnutí, ne na tichou opravu v refaktoru.
 */
class FeeRulesMatrixTest extends DatabaseTestCase
{
    private const DATE = '2099-06-26';

    private int $apId;
    private Closure $qrRegular;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        if (!$this->apId || !DB::table('accounts')->where('member_id', 1)->where('account_attribute_id', 221101)->exists()) {
            $this->markTestSkipped('chybí adresní bod / účty organizace');
        }
        Setting::set('default_fee_member_type_2', $this->fee(300, 35));
        Setting::set('default_fee_member_type_90', $this->fee(250, 35));
        Setting::set('default_fee_member_type_3', '');

        $this->qrRegular = Closure::bind(
            fn (int $id, int $type) => $this->regularFee($id, $type),
            app(PaymentQrService::class),
            PaymentQrService::class
        );
    }

    private function fee(float $amount, int $typeId): int
    {
        return (int) DB::table('fees')->insertGetId([
            'readonly' => 0, 'fee' => $amount, 'from' => '2000-01-01', 'to' => '9999-12-31',
            'type_id' => $typeId, 'name' => 'char fee', 'special_type_id' => null,
        ]);
    }

    private function member(int $type, array $m = []): int
    {
        $id = (int) DB::table('members')->insertGetId($m + [
            'name' => 'Char Fee', 'type' => $type, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $this->apId, 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31',
        ]);
        DB::table('accounts')->insert(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => 100000, 'name' => 'char']);
        return $id;
    }

    private function memberFee(int $member, float $amount, int $priority = 1, int $typeId = 35): void
    {
        DB::table('members_fees')->insert([
            'fee_id' => $this->fee($amount, $typeId), 'member_id' => $member,
            'activation_date' => '2000-01-01', 'deactivation_date' => '9999-12-31', 'priority' => $priority, 'comment' => 'char',
        ]);
    }

    private function tarif(?float $price): int
    {
        return (int) DB::table('speed_classes')->insertGetId([
            'name' => 'char ' . uniqid(), 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => $price,
        ]);
    }

    /** @return array{deduct: float, resolver: ?float, qr: float} */
    private function rules(int $member): array
    {
        $type = (int) DB::table('members')->where('id', $member)->value('type');
        Artisan::call('fees:deduct', ['--force' => true, '--date' => self::DATE, '--member' => $member]);
        $account = (int) DB::table('accounts')->where('member_id', $member)->value('id');

        return [
            'deduct'   => (float) DB::table('transfers')->where('origin_id', $account)->where('type', 1)->where('datetime', self::DATE)->sum('amount'),
            'resolver' => app(FeePolicy::class)->regularFee($member, $type, self::DATE),
            'qr'       => ($this->qrRegular)($member, $type),
        ];
    }

    private function assertRules(array $expected, int $member): void
    {
        $this->assertEquals($expected, $this->rules($member));
    }

    // ── shoda ────────────────────────────────────────────────────────────────

    public function test_radny_clen_zakladni(): void
    {
        $this->assertRules(['deduct' => 300.0, 'resolver' => 300.0, 'qr' => 300.0], $this->member(2));
    }

    public function test_clen_90_zakladni(): void
    {
        $this->assertRules(['deduct' => 250.0, 'resolver' => 250.0, 'qr' => 250.0], $this->member(90));
    }

    public function test_tarif(): void
    {
        $this->assertRules(['deduct' => 199.0, 'resolver' => 199.0, 'qr' => 199.0], $this->member(2, ['speed_class_id' => $this->tarif(199)]));
    }

    public function test_tarif_bez_ceny_propadne(): void
    {
        $this->assertRules(['deduct' => 300.0, 'resolver' => 300.0, 'qr' => 300.0], $this->member(2, ['speed_class_id' => $this->tarif(null)]));
    }

    public function test_individualni_dle_priority(): void
    {
        $m = $this->member(2, ['speed_class_id' => $this->tarif(199)]);
        $this->memberFee($m, 150, 2);
        $this->memberFee($m, 120, 1);
        $this->assertRules(['deduct' => 120.0, 'resolver' => 120.0, 'qr' => 120.0], $m);
    }

    public function test_individualni_nula_osvobodi(): void
    {
        $m = $this->member(2);
        $this->memberFee($m, 0);
        $this->assertRules(['deduct' => 0.0, 'resolver' => 0.0, 'qr' => 0.0], $m);
    }

    // ── ROZDÍLY ──────────────────────────────────────────────────────────────

    /**
     * Čekající / žadatel: resolver (FeePolicy::regularFee) mapuje 1,18 → základní
     * typu 2 a 17 → typu 90, aby smlouva/QR ukazovaly budoucí cenu. Strhává se
     * ale NIC (FeePolicy::NOT_BILLED_TYPES) — srážka ani dohnání po platbě.
     */
    public function test_cekatele_rozdil(): void
    {
        $this->assertRules(['deduct' => 0.0, 'resolver' => 300.0, 'qr' => 300.0], $this->member(1));
        $this->assertRules(['deduct' => 0.0, 'resolver' => 250.0, 'qr' => 250.0], $this->member(17));
        $this->assertRules(['deduct' => 0.0, 'resolver' => 300.0, 'qr' => 300.0], $this->member(18));
    }

    /**
     * ROZDÍL: jiný typ (např. čestný člen 3) s nastaveným základním poplatkem:
     * resolver ho bere, srážka ne.
     */
    public function test_jiny_typ_se_zakladnim_rozdil(): void
    {
        Setting::set('default_fee_member_type_3', $this->fee(50, 35));
        $this->assertRules(['deduct' => 0.0, 'resolver' => 50.0, 'qr' => 50.0], $this->member(3));
    }

    /** QR bez jakéhokoli poplatku spadne na individuální tarif sdružení (member 1). */
    public function test_bez_poplatku_qr_fallback_sdruzeni(): void
    {
        $assoc = app(FeePolicy::class)->individualFee(1, now()->toDateString()) ?? 0.0;
        $this->assertRules(['deduct' => 0.0, 'resolver' => null, 'qr' => (float) $assoc], $this->member(3));
    }

    // ── měsíční příplatky (type 6) ───────────────────────────────────────────

    /** Služby + placená místa: srážka type 6 = AdditionalServices + AllowedSubnetFees (= QR přirážka). */
    public function test_priplatky_shoda(): void
    {
        $m = $this->member(2);
        $this->memberFee($m, 100, 1, 39);
        $this->memberFee($m, 50, 2, 39);
        $subnet = (int) DB::table('subnets')->insertGetId(['name' => 'char', 'network_address' => '10.250.8.0', 'netmask' => '255.255.255.0']);
        DB::table('allowed_subnets')->insert(['member_id' => $m, 'subnet_id' => $subnet, 'enabled' => 1, 'charged' => 1, 'speed_class_id' => $this->tarif(80)]);

        Artisan::call('fees:deduct', ['--force' => true, '--date' => self::DATE, '--member' => $m]);
        $account = (int) DB::table('accounts')->where('member_id', $m)->value('id');
        $deducted = (float) DB::table('transfers')->where('origin_id', $account)->where('type', 6)->where('datetime', self::DATE)->value('amount');

        $this->assertEqualsWithDelta(230.0, $deducted, 0.001);
        $this->assertEqualsWithDelta(230.0, app(\App\Domains\Billing\Fees\FeePolicy::class)->additionalServicesTotal($m, self::DATE) + AllowedSubnetFeesResolver::total($m), 0.001);
    }
}
