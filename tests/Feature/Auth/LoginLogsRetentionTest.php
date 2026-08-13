<?php

namespace Tests\Feature\Auth;

use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Retence login_logs (NIS2/ZoKB): smaže staré, nechá nové; dry-run nemaže;
 * retence 0 = vypnuto.
 */
class LoginLogsRetentionTest extends DatabaseTestCase
{
    private function insertLog(string $time): int
    {
        return (int) DB::table('login_logs')->insertGetId([
            'user_id' => 1, 'time' => $time, 'IP_address' => '10.0.0.1',
        ]);
    }

    public function test_dry_run_nemaze(): void
    {
        Setting::set('login_logs_retention_months', 24);
        $oldId = $this->insertLog('2019-01-01 00:00:00');

        Artisan::call('login-logs:prune', ['--dry-run' => true]);

        $this->assertNotNull(DB::table('login_logs')->where('id', $oldId)->first());
    }

    public function test_prune_smaze_stare_nechá_nove(): void
    {
        Setting::set('login_logs_retention_months', 24);
        $oldId = $this->insertLog('2019-01-01 00:00:00');
        $newId = $this->insertLog(now()->format('Y-m-d H:i:s'));

        Artisan::call('login-logs:prune');

        $this->assertNull(DB::table('login_logs')->where('id', $oldId)->first(), 'starý záznam měl být smazán');
        $this->assertNotNull(DB::table('login_logs')->where('id', $newId)->first(), 'nový záznam měl zůstat');
    }

    public function test_retence_nula_nemaze(): void
    {
        Setting::set('login_logs_retention_months', 0);
        $oldId = $this->insertLog('2019-01-01 00:00:00');

        Artisan::call('login-logs:prune');

        $this->assertNotNull(DB::table('login_logs')->where('id', $oldId)->first());
    }

    /** Retence legacy `logs` (partition drop) — dry-run proběhne bez chyby. */
    public function test_legacy_logs_prune_dry_run_probehne(): void
    {
        $this->assertSame(0, Artisan::call('logs:prune-legacy', ['--dry-run' => true]));
    }
}
