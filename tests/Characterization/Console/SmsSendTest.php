<?php

namespace Tests\Characterization\Console;

use App\Domains\Notifications\Channels\KlikniavolejDriver;
use App\Domains\Notifications\Channels\SmsManagerDriver;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace odesílání SMS fronty (sms:send): vypnuté SMS, výběr
 * ovladače (požadovaný → SmsManager → Klikniavolej, jen aktivní s
 * přihlašovacími údaji), bez ovladače = neodeslaná, limit, příchozí se nesahá.
 */
class SmsSendTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('sms_enabled', '1');
        foreach ([3, 5] as $d) {
            Setting::set("sms_driver_state{$d}", '1');
            Setting::set("sms_password{$d}", '');
            Setting::set("sms_user{$d}", '');
        }
        DB::table('sms_messages')->where('type', 1)->where('state', 1)->update(['state' => 0]);   // čistá fronta (v transakci)
    }

    private function sms(array $over = []): int
    {
        return (int) DB::table('sms_messages')->insertGetId($over + ['user_id' => (int) DB::table('users')->value('id'), 'stamp' => now(), 'send_date' => now(), 'text' => 'x',
            'sender' => '420700000000', 'receiver' => '420777000000', 'driver' => 5, 'type' => 1, 'state' => 1]);
    }

    /** Ovladač pro dané id (stejná logika jako příkaz). */
    private function driver(int $id): ?object
    {
        return app(\App\Domains\Notifications\Channels\SmsDriverResolver::class)->resolve($id);
    }

    public function test_vypnute_a_bez_ovladace(): void
    {
        $a = $this->sms();
        $in = $this->sms(['type' => 0, 'state' => 0]);
        Setting::set('sms_enabled', '0');
        $this->artisan('sms:send')->assertExitCode(0);
        $this->assertSame(1, (int) DB::table('sms_messages')->where('id', $a)->value('state'), 'vypnuto → nic');

        Setting::set('sms_enabled', '1');
        $b = $this->sms(['driver' => 2]);
        $this->artisan('sms:send', ['--limit' => 1])->assertExitCode(1);
        $this->assertSame([2, 'No active driver available for driver_id=5'], [(int) DB::table('sms_messages')->where('id', $a)->value('state'), DB::table('sms_messages')->where('id', $a)->value('message')]);
        $this->assertSame(1, (int) DB::table('sms_messages')->where('id', $b)->value('state'), 'limit 1');
        $this->artisan('sms:send')->assertExitCode(1);
        $this->assertSame('No active driver available for driver_id=2', DB::table('sms_messages')->where('id', $b)->value('message'));
        $this->assertSame(0, (int) DB::table('sms_messages')->where('id', $in)->value('state'), 'příchozí beze změny');
        $this->artisan('sms:send')->assertExitCode(0);   // prázdná fronta
    }

    public function test_vyber_ovladace(): void
    {
        $this->assertNull($this->driver(5));
        Setting::set('sms_driver_state5', '2');
        $this->assertNull($this->driver(5), 'aktivní bez klíče');
        Setting::set('sms_password5', \Tests\Support\Fake::secret('smsmngr'));
        $this->assertInstanceOf(SmsManagerDriver::class, $this->driver(5));
        $this->assertInstanceOf(SmsManagerDriver::class, $this->driver(2), 'nepodporovaný → fallback');

        Setting::set('sms_driver_state5', '1');
        Setting::set('sms_driver_state3', '2');
        Setting::set('sms_user3', 'char');
        $this->assertNull($this->driver(3), 'Klikniavolej bez hesla');
        Setting::set('sms_password3', \Tests\Support\Fake::secret('kav'));
        $this->assertInstanceOf(KlikniavolejDriver::class, $this->driver(5), 'SmsManager neaktivní → Klikniavolej');
    }
}
