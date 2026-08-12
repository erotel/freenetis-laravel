<?php

namespace Tests\Feature\Payments;

use App\Services\PaymentQrService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * QR platba: částka = pravidelný poplatek (tarif) + dodatečné služby.
 * (Oprava z v2.16.0 — dřív jen tarif.)
 */
class PaymentQrServiceTest extends DatabaseTestCase
{
    private PaymentQrService $qr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qr = app(PaymentQrService::class);
    }

    /** Najde člena, pro kterého QR jde sestavit (má účet/IBAN/VS/částku). */
    private function memberWithQr(): array
    {
        foreach (DB::table('members')->where('id', '>', 1)->orderByDesc('id')->limit(400)->pluck('id') as $id) {
            $info = $this->qr->paymentInfoForMember((int) $id);
            if ($info && $info['amount'] > 0) {
                return [(int) $id, (float) $info['amount']];
            }
        }
        $this->markTestSkipped('žádný člen s funkčním QR');
    }

    private function assignService(int $member, float $price, int $priority): void
    {
        $feeId = DB::table('fees')->insertGetId([
            'readonly' => 0, 'fee' => $price, 'from' => '2020-01-01', 'to' => '9999-12-31',
            'type_id'  => 39, 'name' => 'Test služba', 'special_type_id' => null,
        ]);
        DB::table('members_fees')->insert([
            'fee_id' => $feeId, 'member_id' => $member,
            'activation_date' => '2020-01-01', 'deactivation_date' => '9999-12-31',
            'priority' => $priority, 'comment' => 'test',
        ]);
    }

    public function test_castka_je_tarif_plus_sluzba(): void
    {
        [$member, $base] = $this->memberWithQr();

        $this->assignService($member, 50, 1);
        $info = $this->qr->paymentInfoForMember($member);

        $this->assertNotNull($info);
        $this->assertEqualsWithDelta($base + 50, $info['amount'], 0.001);
        // SPAYD musí nést celkovou částku.
        $this->assertStringContainsString('AM:' . number_format($base + 50, 2, '.', ''), $info['spayd']);
    }

    public function test_vic_sluzeb_se_scita(): void
    {
        [$member, $base] = $this->memberWithQr();

        $this->assignService($member, 50, 1);
        $this->assignService($member, 30, 2);
        $info = $this->qr->paymentInfoForMember($member);

        $this->assertEqualsWithDelta($base + 80, $info['amount'], 0.001);
    }

    public function test_svg_se_vygeneruje(): void
    {
        [$member] = $this->memberWithQr();
        $this->assertNotNull($this->qr->svgForMember($member));
    }

    public function test_neexistujici_clen_vraci_null(): void
    {
        $this->assertNull($this->qr->paymentInfoForMember(2147480000));
    }
}
