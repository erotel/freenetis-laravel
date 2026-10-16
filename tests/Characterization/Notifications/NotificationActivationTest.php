<?php

namespace Tests\Characterization\Notifications;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace automatických upozornění (notifications:activate, cron každou
 * minutu, běží jen v :10) před přesunem do domény Notifications; práh „Nízký
 * kredit" (typ 6/26): Feature/Notifications/PaymentNoticeThresholdTest.
 *
 *   pravidla messages_automatical_activations: měsíčně DD/H, týdně D/H, denně /H,
 *   pracovní dny /H, hodinově, po srážce /H; víc pravidel = OR kanálů
 *   výběr: dlužník (kredit < hranice NEBO payment_blocked), imunita nových, odchod
 *   kanály: e-mail (placeholdery, {payment_qr} inline), SMS, přesměrování
 *   (čistý stav pro zprávu), opt-out člena, bílá listina, log_queues, report
 *
 * Bílá listina k dnešnímu datu vyjme z přesměrování (do 2026-10-16 se
 * porovnávala s číslem dne v měsíci a nikdy neplatila).
 */
class NotificationActivationTest extends DatabaseTestCase
{
    private const MSG_DEBTOR = 5;
    private const MSG_DEBTOR_CLEN = 114;
    private array $qrFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 08:10:00'); // pátek
        foreach (['finance_enabled' => 1, 'email_enabled' => 1, 'sms_enabled' => 1, 'redirection_enabled' => 1, 'sms_driver' => 2,
                  'sms_sender_number' => '+420700000001', 'debtor_boundary' => -1000000, 'big_debtor_boundary' => 0, 'initial_debtor_immunity' => 0,
                  'email_subject_prefix' => 'CHAR', 'email_default_email' => 'noc@example.invalid', 'deduct_day' => 16] as $k => $v) {
            Setting::set($k, $v);
        }
        // izolace: jen testovací pravidla a jen testovací blokovaní členové
        DB::table('messages_automatical_activations')->delete();
        DB::table('members')->where('payment_blocked', 1)->update(['payment_blocked' => 0]);
        DB::table('messages_ip_addresses')->whereIn('message_id', [self::MSG_DEBTOR, self::MSG_DEBTOR_CLEN])->delete();
        DB::table('messages')->whereIn('id', [self::MSG_DEBTOR, self::MSG_DEBTOR_CLEN])
            ->update(['email_text' => 'Dluh {member_name} #{member_id}', 'sms_text' => 'SMS {member_name}', 'ignore_whitelist' => 0]);
        $this->qrFiles = glob(storage_path('app/private/qr/*.png')) ?: [];
    }

    protected function tearDown(): void
    {
        foreach (array_diff(glob(storage_path('app/private/qr/*.png')) ?: [], $this->qrFiles) as $f) @unlink($f);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rule(int $messageId, int $type, string $attr, array $channels = ['email', 'sms', 'redirection'], ?string $report = null): void
    {
        DB::table('messages_automatical_activations')->insert(['message_id' => $messageId, 'type' => $type, 'attribute' => $attr,
            'email_enabled' => (int) in_array('email', $channels), 'sms_enabled' => (int) in_array('sms', $channels),
            'redirection_enabled' => (int) in_array('redirection', $channels), 'send_activation_to_email' => $report]);
    }

    /** @return array{member:int, ips:int[], email:string, phone:string} */
    private function debtor(int $type = 2, array $m = []): array
    {
        $apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $id = (int) DB::table('members')->insertGetId($m + ['name' => 'Char Dluznik', 'type' => $type, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $apId, 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31', 'payment_blocked' => 1,
            'notification_by_email' => 1, 'notification_by_sms' => 1, 'notification_by_redirection' => 1]);
        DB::table('accounts')->insert(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => 0, 'name' => 'c']);
        $uid = (int) DB::table('users')->insertGetId(['member_id' => $id, 'login' => 'charact_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'D', 'comment' => '']);
        $email = "charact{$id}@example.invalid";
        $phone = '+42077' . str_pad((string) $id, 7, '0', STR_PAD_LEFT);
        foreach ([[20, $email], [21, $phone]] as [$t, $v]) {
            DB::table('users_contacts')->insert(['user_id' => $uid, 'contact_id' => DB::table('contacts')->insertGetId(['type' => $t, 'value' => $v])]);
        }
        $iface = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'e', 'device_id' => DB::table('devices')->insertGetId(['name' => 'c', 'type' => 1, 'user_id' => $uid])]);
        $ip = (int) DB::table('ip_addresses')->insertGetId(['iface_id' => $iface, 'ip_address' => '10.250.25.' . random_int(1, 250)]);
        return ['member' => $id, 'ips' => [$ip], 'email' => $email, 'phone' => $phone];
    }

    private function run10(?string $at = null)
    {
        if ($at) Carbon::setTestNow($at);
        return $this->artisan('notifications:activate');
    }

    private function redirected(int $messageId, array $ips): int
    {
        return DB::table('messages_ip_addresses')->where('message_id', $messageId)->whereIn('ip_address_id', $ips)->count();
    }

    private function log(int $messageId)
    {
        $name = DB::table('messages')->where('id', $messageId)->value('name');
        return DB::table('log_queues')->where('description', 'Upozorňovací zpráva "' . $name . '" byla automaticky aktivována')->orderByDesc('id')->first();
    }

    // ── kanály ───────────────────────────────────────────────────────────────

    public function test_dluznik_email_sms_presmerovani_log_report(): void
    {
        $x = $this->debtor();
        $stale = (int) DB::table('ip_addresses')->insertGetId(['ip_address' => '10.250.25.254']);
        DB::table('messages_ip_addresses')->insert(['message_id' => self::MSG_DEBTOR, 'ip_address_id' => $stale, 'datetime' => now()]);
        $this->rule(self::MSG_DEBTOR, 3, '/8', ['email', 'sms', 'redirection'], 'a@example.invalid, b@example.invalid');

        $this->run10()->assertExitCode(0);

        $mail = DB::table('email_queues')->where('to', $x['email'])->first();
        $name = DB::table('messages')->where('id', self::MSG_DEBTOR)->value('name');
        $this->assertSame(['CHAR :: ' . $name, "Dluh Char Dluznik #{$x['member']}", 'noc@example.invalid'], [$mail->subject, $mail->body, $mail->from]);
        $sms = DB::table('sms_messages')->where('receiver', $x['phone'])->first();
        $this->assertSame(['SMS Char Dluznik', '+420700000001', 2, 1], [$sms->text, $sms->sender, (int) $sms->driver, (int) $sms->user_id]);
        $r = DB::table('messages_ip_addresses')->where('message_id', self::MSG_DEBTOR)->where('ip_address_id', $x['ips'][0])->first();
        $this->assertSame([1, 'Auto-aktivace: ' . $name], [(int) $r->user_id, $r->comment]);
        $this->assertSame(0, $this->redirected(self::MSG_DEBTOR, [$stale]), 'čistý stav pro zprávu');

        $log = $this->log(self::MSG_DEBTOR);
        $this->assertSame([3, 0], [(int) $log->type, (int) $log->state]);
        $this->assertSame("Přesměrování bylo deaktivováno pro 1 IP adres.\nPřesměrování bylo aktivováno pro 1 IP adres.\n"
            . "E-mail byl odeslán pro 1 e-mailových adres.\nSMS zpráva byla odeslána pro 1 telefonních čísel.", $log->exception_backtrace);

        foreach (['a@example.invalid', 'b@example.invalid'] as $to) {
            $rep = DB::table('email_queues')->where('to', $to)->first();
            $this->assertSame('CHAR :: Aktivace zprávy: ' . $name, $rep->subject);
            $this->assertStringContainsString('Počet členů: 1<br />', $rep->body);
        }
    }

    public function test_opt_out_bila_listina_a_clen_na_zpravu_114(): void
    {
        $optOut = $this->debtor(2, ['notification_by_email' => 0, 'notification_by_sms' => 0, 'notification_by_redirection' => 0]);
        $wl     = $this->debtor(2);
        DB::table('members_whitelists')->insert(['member_id' => $wl['member'], 'permanent' => 1, 'since' => '2000-01-01', 'until' => '9999-12-31']);
        $clen   = $this->debtor(90);
        $this->rule(self::MSG_DEBTOR, 5, '');
        $this->rule(self::MSG_DEBTOR_CLEN, 5, '', ['redirection']);

        $this->run10()->assertExitCode(0);

        $this->assertFalse(DB::table('email_queues')->where('to', $optOut['email'])->exists());
        $this->assertFalse(DB::table('sms_messages')->where('receiver', $optOut['phone'])->exists());
        $this->assertSame(0, $this->redirected(self::MSG_DEBTOR, $optOut['ips']));
        $this->assertStringContainsString('Vynecháno kvůli vlastnímu odhlášení člena: 1 e-mailů, 1 SMS, 1 přesměrování.', $this->log(self::MSG_DEBTOR)->exception_backtrace);

        $this->assertSame(0, $this->redirected(self::MSG_DEBTOR, $wl['ips']), 'bílá listina vyjme z přesměrování');
        $this->assertStringContainsString('Přesměrování vynecháno kvůli bílé listině: 1 členů.', $this->log(self::MSG_DEBTOR)->exception_backtrace);
        $this->assertTrue(DB::table('email_queues')->where('to', $wl['email'])->exists(), 'e-mail dostane i tak');

        // zpráva s ignore_whitelist přesměruje i whitelistovaného
        DB::table('messages')->where('id', self::MSG_DEBTOR)->update(['ignore_whitelist' => 1]);
        $this->run10();
        $this->assertSame(1, $this->redirected(self::MSG_DEBTOR, $wl['ips']));

        $this->assertSame([0, 1], [$this->redirected(self::MSG_DEBTOR, $clen['ips']), $this->redirected(self::MSG_DEBTOR_CLEN, $clen['ips'])]);
        $this->assertFalse(DB::table('email_queues')->where('to', $clen['email'])->exists(), 'pravidlo zprávy 114 jen přesměrování');
    }

    public function test_vyber_imunita_odchod_hranice(): void
    {
        Setting::set('initial_debtor_immunity', 30);
        Setting::set('debtor_boundary', 100);
        $new     = $this->debtor(2, ['entrance_date' => now()->subDays(10)->toDateString()]);
        $leaving = $this->debtor(2, ['leaving_date' => now()->subDay()->toDateString()]);
        $low     = $this->debtor(2, ['payment_blocked' => 0]);  // kredit 0 < hranice 100
        $ok      = $this->debtor(2, ['payment_blocked' => 0]);
        DB::table('accounts')->where('member_id', $ok['member'])->update(['balance' => 500]);
        $this->rule(self::MSG_DEBTOR, 5, '', ['redirection']);

        $this->run10();
        $this->assertSame([0, 0, 1, 0], [$this->redirected(self::MSG_DEBTOR, $new['ips']), $this->redirected(self::MSG_DEBTOR, $leaving['ips']),
            $this->redirected(self::MSG_DEBTOR, $low['ips']), $this->redirected(self::MSG_DEBTOR, $ok['ips'])]);
    }

    public function test_qr_inline_v_emailu(): void
    {
        DB::table('messages')->where('id', self::MSG_DEBTOR)->update(['email_text' => 'Zaplaťte {payment_qr}']);
        $x = $this->debtor();
        $this->rule(self::MSG_DEBTOR, 5, '', ['email']);
        $this->run10();
        $mail = DB::table('email_queues')->where('to', $x['email'])->first();
        $att  = DB::table('email_queue_attachments')->where('email_queue_id', $mail->id)->first();
        if ($att) {
            $this->assertStringContainsString('<img src="cid:payment_qr.png"', $mail->body);
            $this->assertSame(['payment_qr.png', 'image/png', 1, storage_path('app/private/qr/qr_' . $mail->id . '.png')], [$att->name, $att->mime, (int) $att->inline, $att->path]);
        } else {
            $this->assertSame('Zaplaťte ', $mail->body, 'QR nejde sestavit → placeholder zmizí');
        }
    }

    // ── pravidla a brány ─────────────────────────────────────────────────────

    public function test_pravidla_casu_a_brany(): void
    {
        $x = $this->debtor();
        $cases = [
            // [typ, atribut, čas, aktivní?]
            [1, '16/8', '2026-10-16 08:10:00', true],  [1, '15/8', '2026-10-16 08:10:00', false],
            [2, '5/8', '2026-10-16 08:10:00', true],   [2, '1/8', '2026-10-16 08:10:00', false],
            [3, '/8', '2026-10-16 08:10:00', true],    [3, '/9', '2026-10-16 08:10:00', false],
            [4, '/8', '2026-10-17 08:10:00', false],   [4, '/8', '2026-10-16 08:10:00', true],
            [6, '/8', '2026-10-16 08:10:00', true],    [6, '/8', '2026-10-15 08:10:00', false],
            [9, '', '2026-10-16 08:10:00', false],
        ];
        foreach ($cases as [$type, $attr, $at, $active]) {
            DB::table('messages_automatical_activations')->delete();
            DB::table('messages_ip_addresses')->where('message_id', self::MSG_DEBTOR)->delete();
            $this->rule(self::MSG_DEBTOR, $type, $attr, ['redirection']);
            $this->run10($at);
            $this->assertSame($active ? 1 : 0, $this->redirected(self::MSG_DEBTOR, $x['ips']), "typ {$type} {$attr} @ {$at}");
        }

        DB::table('messages_ip_addresses')->where('message_id', self::MSG_DEBTOR)->delete();
        $this->run10('2026-10-16 08:11:00');
        $this->assertSame(0, $this->redirected(self::MSG_DEBTOR, $x['ips']), 'mimo minutu :10 nic');
        $this->artisan('notifications:activate', ['--force' => true]);
        $this->assertSame(1, $this->redirected(self::MSG_DEBTOR, $x['ips']), '--force ignoruje minutu i pravidla');

        Setting::set('redirection_enabled', 0);
        DB::table('messages_ip_addresses')->where('message_id', self::MSG_DEBTOR)->delete();
        $this->run10('2026-10-16 08:10:00');
        $this->assertSame(0, $this->redirected(self::MSG_DEBTOR, $x['ips']), 'globálně vypnuté přesměrování');

        Setting::set('finance_enabled', 0);
        $this->run10()->expectsOutputToContain('Finance disabled, skipping.');
    }
}
