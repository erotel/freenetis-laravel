<?php

namespace Tests\Characterization\Notifications;

use App\Models\Setting;
use App\Domains\Notifications\Actions\SyncDebtorRedirectAction;
use App\Domains\Notifications\Actions\SyncPendingCustomerRedirectAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace automatických přesměrování (crony members:redirect-* kromě
 * former, služby SyncDebtorRedirectAction / SyncPendingCustomerRedirectAction)
 * před přesunem do domény Notifications. Vzor: smazat přesměrování dané zprávy
 * a znovu založit pro aktuální množinu členů (comment „Auto: …", user 1).
 */
class AutoRedirectsTest extends DatabaseTestCase
{
    private const MSG_INTERRUPTED = 4;
    private const MSG_DEBTOR      = 5;
    private const MSG_APPLICANT   = 25;   // type 16
    private const MSG_DEBTOR_CLEN = 114;
    private const MSG_PENDING     = 127;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('payment_blocked_redirect_enabled', 1);
        Setting::set('pending_customer_redirect_enabled', 1);
        Setting::set('membership_interrupt_enabled', 1);
        Setting::set('self_registration', 1);
        Setting::set('applicant_connection_test_duration', 14);
    }

    /** @return array{member:int, ips:int[]} */
    private function member(int $type, array $m = [], int $ipCount = 1): array
    {
        $apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $id = (int) DB::table('members')->insertGetId($m + ['name' => 'Char Auto', 'type' => $type, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $apId, 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $uid = (int) DB::table('users')->insertGetId(['member_id' => $id, 'login' => 'charauto_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'A', 'comment' => '']);
        $iface = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'e',
            'device_id' => DB::table('devices')->insertGetId(['name' => 'char-a', 'type' => 1, 'user_id' => $uid])]);
        $ips = [];
        for ($i = 0; $i < $ipCount; $i++) {
            $ips[] = (int) DB::table('ip_addresses')->insertGetId(['iface_id' => $iface, 'ip_address' => '10.250.24.' . random_int(1, 250)]);
        }
        return ['member' => $id, 'ips' => $ips];
    }

    private function redirects(int $messageId, array $ips): array
    {
        return DB::table('messages_ip_addresses')->where('message_id', $messageId)->whereIn('ip_address_id', $ips)->orderBy('ip_address_id')
            ->get()->map(fn ($r) => [(int) $r->ip_address_id, (int) $r->user_id, $r->comment])->all();
    }

    private function stale(int $messageId): int
    {
        $ip = (int) DB::table('ip_addresses')->insertGetId(['ip_address' => '10.250.24.254']);
        DB::table('messages_ip_addresses')->insert(['message_id' => $messageId, 'ip_address_id' => $ip, 'datetime' => now(), 'comment' => 'stare']);
        return $ip;
    }

    // ── neplatiči ────────────────────────────────────────────────────────────

    public function test_neplatici_zakaznik_zprava_5_clen_zprava_114_prestavba(): void
    {
        $customer = $this->member(2, ['payment_blocked' => 1], 2);
        $regular  = $this->member(90, ['payment_blocked' => 1]);
        $paid     = $this->member(2);
        $former   = $this->member(16, ['payment_blocked' => 1]);
        $stale    = $this->stale(self::MSG_DEBTOR);

        $this->artisan('members:redirect-blocked')->assertExitCode(0);  // počty v DB kopii zahrnují i reálné členy

        $c = 'Auto: nedostatečná výše konta (prepaid)';
        $this->assertSame(array_map(fn ($ip) => [$ip, 1, $c], $customer['ips']), $this->redirects(self::MSG_DEBTOR, $customer['ips']));
        $this->assertSame([[$regular['ips'][0], 1, $c]], $this->redirects(self::MSG_DEBTOR_CLEN, $regular['ips']));
        $this->assertSame([], $this->redirects(self::MSG_DEBTOR, $paid['ips']));
        $this->assertSame([], $this->redirects(self::MSG_DEBTOR, $former['ips']), 'bývalý ne');
        $this->assertSame([], $this->redirects(self::MSG_DEBTOR, [$stale]), 'přestavba smaže staré');
    }

    public function test_sluzba_neplatice_odblokovani_smaze_obe_zpravy(): void
    {
        $x = $this->member(2);
        DB::table('messages_ip_addresses')->insert([
            ['message_id' => self::MSG_DEBTOR, 'ip_address_id' => $x['ips'][0], 'datetime' => now()],
            ['message_id' => self::MSG_DEBTOR_CLEN, 'ip_address_id' => $x['ips'][0], 'datetime' => now()],
        ]);
        $svc = app(SyncDebtorRedirectAction::class);
        $this->assertTrue($svc->handle($x['member']));
        $this->assertSame([[], []], [$this->redirects(self::MSG_DEBTOR, $x['ips']), $this->redirects(self::MSG_DEBTOR_CLEN, $x['ips'])]);
        $this->assertFalse($svc->handle($x['member']), 'nic ke smazání');
        $this->assertFalse($svc->handle($this->member(18, [], 0)['member']), 'bez IP');
        $this->assertFalse($svc->handle($this->member(1, ['payment_blocked' => 1])['member']), 'žadatel nemá zprávu');

        Setting::set('payment_blocked_redirect_enabled', 0);
        $this->assertFalse($svc->handle($this->member(2, ['payment_blocked' => 1])['member']));
        $this->artisan('members:redirect-blocked')->expectsOutputToContain('Payment blocked redirect disabled, skipping.')->assertExitCode(0);
    }

    // ── čekající zákazníci ───────────────────────────────────────────────────

    public function test_cekajici_zakaznici_prestavba_a_sluzba(): void
    {
        $pending = $this->member(18, [], 2);
        $active  = $this->member(2);
        $stale   = $this->stale(self::MSG_PENDING);

        $this->artisan('members:redirect-pending-customers')->assertExitCode(0);
        $c = 'Auto: čekající zákazník (nepodepsaná smlouva)';
        $this->assertSame(array_map(fn ($ip) => [$ip, 1, $c], $pending['ips']), $this->redirects(self::MSG_PENDING, $pending['ips']));
        $this->assertSame([], $this->redirects(self::MSG_PENDING, array_merge($active['ips'], [$stale])));

        DB::table('members')->where('id', $pending['member'])->update(['type' => 2]);
        $this->assertTrue(app(SyncPendingCustomerRedirectAction::class)->handle($pending['member']), 'po podpisu smazat');
        $this->assertSame([], $this->redirects(self::MSG_PENDING, $pending['ips']));
    }

    // ── přerušení ────────────────────────────────────────────────────────────

    public function test_preruseni_aktivni_dnes(): void
    {
        $fee = (int) DB::table('fees')->insertGetId(['readonly' => 0, 'fee' => 0, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => 35, 'name' => 'c', 'special_type_id' => 1]);
        $int = function (string $from, string $to) use ($fee) {
            $x  = $this->member(2);
            $mf = (int) DB::table('members_fees')->insertGetId(['fee_id' => $fee, 'member_id' => $x['member'], 'activation_date' => $from, 'deactivation_date' => $to, 'priority' => 0, 'comment' => 'c']);
            DB::table('membership_interrupts')->insert(['member_id' => $x['member'], 'members_fee_id' => $mf, 'end_after_interrupt_end' => 0]);
            return $x;
        };
        $now    = $int(now()->subDay()->toDateString(), now()->addMonth()->toDateString());
        $future = $int(now()->addDay()->toDateString(), now()->addMonth()->toDateString());
        $past   = $int('2020-01-01', now()->subDay()->toDateString());

        $this->artisan('members:redirect-interrupted')->assertExitCode(0);
        $this->assertSame([[$now['ips'][0], 1, 'Auto: přerušené členství']], $this->redirects(self::MSG_INTERRUPTED, $now['ips']));
        $this->assertSame([], $this->redirects(self::MSG_INTERRUPTED, array_merge($future['ips'], $past['ips'])));

        Setting::set('membership_interrupt_enabled', 0);
        $this->artisan('members:redirect-interrupted')->expectsOutputToContain('Membership interrupts disabled, skipping.');
    }

    // ── žadatelé po testovacím připojení ─────────────────────────────────────

    public function test_vyprsely_test_zadatele(): void
    {
        $expired = $this->member(1, ['registration' => 0, 'applicant_connected_from' => now()->subDays(15)->toDateString()]);
        $fresh   = $this->member(1, ['registration' => 0, 'applicant_connected_from' => now()->subDays(14)->toDateString()]);
        $signed  = $this->member(1, ['registration' => 1, 'applicant_connected_from' => now()->subDays(30)->toDateString()]);

        $this->artisan('members:redirect-expired-applicants')->expectsOutputToContain('Found 1 IP addresses of expired applicants.')->assertExitCode(0);
        $this->assertSame([[$expired['ips'][0], 1, 'Auto: vypršelo testovací připojení']], $this->redirects(self::MSG_APPLICANT, $expired['ips']));
        $this->assertSame([], $this->redirects(self::MSG_APPLICANT, array_merge($fresh['ips'], $signed['ips'])));

        Setting::set('applicant_connection_test_duration', 0);
        $this->artisan('members:redirect-expired-applicants')->expectsOutputToContain('applicant_connection_test_duration not set or 0, skipping.');
        Setting::set('self_registration', 0);
        $this->artisan('members:redirect-expired-applicants')->expectsOutputToContain('Self-registration disabled, skipping.');
    }
}
