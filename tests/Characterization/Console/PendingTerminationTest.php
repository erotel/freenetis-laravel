<?php

namespace Tests\Characterization\Console;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace members:mark-pending-termination (denně 06:30, akce jen
 * v pending_termination_day): kandidát = zablokovaný za neplacení od
 * předchozího měsíce nebo dřív, ne bývalý, ne ve výpovědní lhůtě, ještě
 * neoznačený; označení, audit, e-mail adminovi se seznamem (VS, dluh, dny).
 */
class PendingTerminationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('admin_notification_email', 'noc@example.invalid');
        Setting::set('email_subject_prefix', 'FN');
        Setting::set('pending_termination_day', (string) (int) date('j'));
    }

    private function member(array $over, float $balance = -350): int
    {
        $id = (int) DB::table('members')->insertGetId($over + ['name' => 'Char Dluznik', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'), 'entrance_date' => '2020-01-01',
            'leaving_date' => '9999-12-31', 'payment_blocked' => 1, 'pending_termination' => 0, 'payment_blocked_since' => now()->subMonthNoOverflow()->startOfMonth()->toDateString()]);
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => $balance, 'name' => 'c']);
        DB::table('variable_symbols')->insert(['account_id' => $acc, 'variable_symbol' => (string) random_int(10000000, 99999999)]);
        return $id;
    }

    private function flag(int $id): int
    {
        return (int) DB::table('members')->where('id', $id)->value('pending_termination');
    }

    public function test_kandidati_oznaceni_a_email(): void
    {
        $due      = $this->member([]);
        $thisMonth = $this->member(['payment_blocked_since' => now()->startOfMonth()->toDateString()]);
        $former   = $this->member(['type' => 16]);
        $notice   = $this->member(['leaving_date' => now()->addDays(10)->toDateString()]);
        $notBlocked = $this->member(['payment_blocked' => 0]);
        $already  = $this->member(['pending_termination' => 1]);

        $this->artisan('members:mark-pending-termination')->assertExitCode(0);

        $this->assertSame([1, 0, 0, 0, 0, 1], array_map(fn ($id) => $this->flag($id), [$due, $thisMonth, $former, $notice, $notBlocked, $already]));
        $audit = DB::table('audit_logs')->where('action', 'auto_pending_termination')->orderByDesc('id')->first();
        $this->assertContains($due, json_decode($audit->new_values, true)['member_ids']);

        $mail = DB::table('email_queues')->where('to', 'noc@example.invalid')->orderByDesc('id')->first();
        $this->assertSame('FN :: Kandidáti na ukončení smlouvy (' . date('Y-m-d') . ')', $mail->subject);
        $vs = DB::table('variable_symbols as vs')->join('accounts as a', 'a.id', '=', 'vs.account_id')->where('a.member_id', $due)->value('vs.variable_symbol');
        $this->assertStringContainsString("<td>{$due}</td><td>Char Dluznik</td><td>{$vs}</td><td style=\"text-align:right\">350,00 Kč</td>", $mail->body);
    }

    public function test_jiny_den_a_bez_kandidatu(): void
    {
        $due = $this->member([]);
        Setting::set('pending_termination_day', (string) ((int) date('j') % 28 + 1));
        $this->artisan('members:mark-pending-termination')->assertExitCode(0);
        $this->assertSame(0, $this->flag($due), 'jiný den → nic');
        $this->artisan('members:mark-pending-termination', ['--force' => true])->assertExitCode(0);
        $this->assertSame(1, $this->flag($due));

        $before = DB::table('email_queues')->count();
        $this->artisan('members:mark-pending-termination', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before, DB::table('email_queues')->count(), 'bez kandidátů bez e-mailu');
    }
}
