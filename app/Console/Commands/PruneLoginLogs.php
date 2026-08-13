<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retence `login_logs` (NIS2/ZoKB): smaže přihlašovací záznamy starší než
 * `login_logs_retention_months` (Setting, default 24; 0 = nikdy nemazat).
 *
 * Tabulka není partitioned (na rozdíl od audit_logs), takže mažeme chunkovaně
 * (LIMIT v cyklu), ať se nedrží dlouhý zámek nad velkou tabulkou. Plánováno
 * denně.
 */
class PruneLoginLogs extends Command
{
    protected $signature = 'login-logs:prune {--dry-run : Jen spočítat, nemazat}';

    protected $description = 'Smaže staré přihlašovací záznamy (login_logs) dle retence';

    private const CHUNK = 5000;

    public function handle(): int
    {
        $months = (int) Setting::get('login_logs_retention_months', 24);
        if ($months <= 0) {
            $this->info('Retence login_logs vypnuta (login_logs_retention_months <= 0).');
            return self::SUCCESS;
        }

        $cutoff = now()->subMonths($months)->format('Y-m-d H:i:s');

        if ($this->option('dry-run')) {
            $count = DB::table('login_logs')->where('time', '<', $cutoff)->count();
            $this->info("[dry-run] Ke smazání (starší než {$cutoff}): {$count} záznamů.");
            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $n = DB::table('login_logs')->where('time', '<', $cutoff)->limit(self::CHUNK)->delete();
            $deleted += $n;
        } while ($n > 0);

        $this->info("Smazáno {$deleted} přihlašovacích záznamů starších než {$cutoff}.");
        return self::SUCCESS;
    }
}
