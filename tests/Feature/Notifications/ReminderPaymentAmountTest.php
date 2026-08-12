<?php

namespace Tests\Feature\Notifications;

use App\Models\Message;
use App\Services\PaymentQrService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Dynamická částka v upozornění na placení: placeholder {payment_amount}
 * = tarif + dodatečné služby (stejný zdroj jako QR). Nahradil natvrdo „320kč".
 * (v2.17.0)
 */
class ReminderPaymentAmountTest extends DatabaseTestCase
{
    private function memberWithQr(): int
    {
        $qr = app(PaymentQrService::class);
        foreach (DB::table('members')->where('id', '>', 1)->orderByDesc('id')->limit(400)->pluck('id') as $id) {
            $info = $qr->paymentInfoForMember((int) $id);
            if ($info && $info['amount'] > 0) {
                return (int) $id;
            }
        }
        $this->markTestSkipped('žádný člen s funkčním QR');
    }

    public function test_placeholder_odpovida_castce_qr(): void
    {
        $member = $this->memberWithQr();

        $info = app(PaymentQrService::class)->paymentInfoForMember($member);
        $ph   = Message::buildPlaceholders($member);

        // {payment_amount} je částka z QR zformátovaná jako {balance} (2 des. místa).
        $this->assertSame(number_format($info['amount'], 2, ',', ' '), $ph['payment_amount']);
    }

    public function test_typ6_uz_nema_natvrdo_320(): void
    {
        $msg = Message::where('type', 6)->first();
        $this->assertNotNull($msg, 'zpráva typ 6 musí existovat');

        foreach (['text', 'email_text'] as $col) {
            $this->assertStringContainsString('{payment_amount}', (string) $msg->$col, "$col má mít placeholder");
            $this->assertStringNotContainsString('320kč', (string) $msg->$col, "$col nesmí mít natvrdo 320kč");
        }
    }

    public function test_substituce_nahradi_placeholder_cislem(): void
    {
        $member = $this->memberWithQr();
        $msg    = Message::where('type', 6)->first();

        $rendered = Message::substitute($msg->email_text, Message::buildPlaceholders($member));

        $this->assertStringNotContainsString('{payment_amount}', $rendered, 'placeholder má být nahrazen');
        // Vyrenderovaná fráze musí obsahovat číslo a „Kč".
        $this->assertMatchesRegularExpression('/výše platby je[^<]*\d[\d\s.,]*\s*Kč/u', $rendered);
    }
}
