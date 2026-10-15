<?php

namespace Tests\Feature\Notifications;

use App\Console\Commands\NotificationActivation;
use App\Models\Message;
use App\Models\Setting;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Upomínka „Nízký kredit" (notifications:activate, typ 6 zákazník / 26 člen):
 * kdo dostane upozornění, protože kredit nepokryje příští měsíční srážku.
 * Práh = co se skutečně strhne (FeePolicy): pravidelný poplatek
 * (individuální dle priority → tarif → základní) + příplatky (služby,
 * placená místa).
 */
class PaymentNoticeThresholdTest extends DatabaseTestCase
{
    private int $apId;
    private Closure $notify;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        if (!$this->apId) {
            $this->markTestSkipped('chybí adresní bod');
        }
        Setting::set('default_fee_member_type_2', $this->fee(320, 35));
        Setting::set('default_fee_member_type_90', $this->fee(250, 35));
        $this->notify = Closure::bind(
            fn (int $type) => $this->getMembersToNotify($type, 0.0, 0.0, 0),
            app(NotificationActivation::class),
            NotificationActivation::class
        );
    }

    private function fee(float $amount, int $typeId): int
    {
        return (int) DB::table('fees')->insertGetId([
            'readonly' => 0, 'fee' => $amount, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => $typeId, 'name' => 'char', 'special_type_id' => null,
        ]);
    }

    private function member(int $type, float $balance, ?float $tarif = null): int
    {
        $sc = $tarif === null ? null : (int) DB::table('speed_classes')->insertGetId([
            'name' => 'char ' . uniqid(), 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => $tarif,
        ]);
        $id = (int) DB::table('members')->insertGetId([
            'name' => 'Char Notice', 'type' => $type, 'registration' => 1, 'locked' => 0, 'address_point_id' => $this->apId,
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31', 'speed_class_id' => $sc,
        ]);
        DB::table('accounts')->insert(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => $balance, 'name' => 'char']);
        return $id;
    }

    private function memberFee(int $member, float $amount, int $priority, int $typeId = 35): void
    {
        DB::table('members_fees')->insert([
            'fee_id' => $this->fee($amount, $typeId), 'member_id' => $member,
            'activation_date' => '2000-01-01', 'deactivation_date' => '9999-12-31', 'priority' => $priority, 'comment' => 'char',
        ]);
    }

    private function notified(int $type): array
    {
        return ($this->notify)($type)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_tarif_nad_kreditem_upozorni_pod_ne(): void
    {
        $low  = $this->member(2, 300, 320);
        $ok   = $this->member(2, 400, 320);
        $ids  = $this->notified(Message::PAYMENT_NOTICE_MESSAGE);
        $this->assertContains($low, $ids);
        $this->assertNotContains($ok, $ids);
    }

    public function test_zakladni_poplatek_dle_typu(): void
    {
        $customer = $this->member(2, 300);
        $regular  = $this->member(90, 300);
        $this->assertContains($customer, $this->notified(Message::PAYMENT_NOTICE_MESSAGE), '300 < 320');
        $this->assertNotContains($regular, $this->notified(Message::PAYMENT_NOTICE_MESSAGE_CLEN), '300 ≥ 250');
    }

    /** Víc individuálních poplatků → platí ten s nejnižší prioritou (jako srážka). */
    public function test_individualni_dle_priority(): void
    {
        $m = $this->member(2, 300, 320);
        $this->memberFee($m, 100, 1);
        $this->memberFee($m, 500, 2);
        $this->assertNotContains($m, $this->notified(Message::PAYMENT_NOTICE_MESSAGE), 'strhne se 100, kredit 300 stačí');
    }

    /** Dodatečná služba se k prahu přičte (strhne se taky), ne aby nahradila tarif. */
    public function test_sluzba_se_pricita_k_tarifu(): void
    {
        $m = $this->member(2, 340, 320);
        $this->memberFee($m, 50, 1, 39);
        $this->assertContains($m, $this->notified(Message::PAYMENT_NOTICE_MESSAGE), 'strhne se 320 + 50 = 370 > 340');
    }

    public function test_osvobozeny_nedostane(): void
    {
        $m = $this->member(2, 10, 320);
        $this->memberFee($m, 0, 1);
        $this->assertNotContains($m, $this->notified(Message::PAYMENT_NOTICE_MESSAGE));
    }
}
