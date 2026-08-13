<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retence zmrazené legacy tabulky `logs` (starý Kohana audit; od migrace na
 * Laravel se do ní nepíše — nový audit je v `audit_logs`).
 *
 * Tabulka je RANGE partitioning po dnech (`to_days(time)`). Retence = dropnout
 * denní partitions starší než retenční okno (sdílí s novým auditem
 * `audit.retention_months`, default 24; 0 = nemazat). Drop partition je
 * okamžitý (žádné DELETE). Data jsou zmrazená, takže command po zastarání
 * jednou pročistí a dál je to no-op. Plánováno denně.
 */
class PruneLegacyLogs extends Command
{
    protected $signature = 'logs:prune-legacy {--dry-run : Jen vypsat, co by se dropnulo}';

    protected $description = 'Retence legacy tabulky logs — dropne partitions starší než retence';

    public function handle(): int
    {
        if (!Schema::hasTable('logs')) {
            $this->info('Tabulka logs neexistuje — přeskočeno.');
            return self::SUCCESS;
        }

        $months = (int) config('audit.retention_months', 24);
        if ($months <= 0) {
            $this->info('Retence vypnuta (audit.retention_months <= 0).');
            return self::SUCCESS;
        }

        // Hranice v jednotkách to_days (stejně jako partition boundaries).
        $cutoff = (int) DB::selectOne(
            "SELECT TO_DAYS(NOW() - INTERVAL {$months} MONTH) AS c"
        )->c;

        // Denní partitions (mimo p_first / MAXVALUE) s číselnou hranicí.
        $parts = DB::select("
            SELECT PARTITION_NAME AS name, PARTITION_DESCRIPTION AS descr
            FROM information_schema.PARTITIONS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'logs'
              AND PARTITION_NAME IS NOT NULL
              AND PARTITION_NAME <> 'p_first'
        ");

        if (empty($parts)) {
            $this->info('logs není partitioned (nebo jen p_first) — nic k retenci.');
            return self::SUCCESS;
        }

        $dryRun  = (bool) $this->option('dry-run');
        $dropped = 0;

        foreach ($parts as $p) {
            $descr = trim((string) $p->descr);
            if (!ctype_digit($descr)) {
                continue; // MAXVALUE apod.
            }
            // Partition drží řádky s to_days(time) < descr → celá je starší než
            // retence, když je její horní hranice <= cutoff.
            if ((int) $descr <= $cutoff) {
                if ($dryRun) {
                    $this->line("[dry-run] DROP PARTITION {$p->name} (< {$descr})");
                } else {
                    DB::statement("ALTER TABLE logs DROP PARTITION {$p->name}");
                    $this->info("Dropnuta partition {$p->name}.");
                }
                $dropped++;
            }
        }

        $this->info("Hotovo. Partitions za retencí ({$months} m.): {$dropped}" . ($dryRun ? ' (dry-run)' : '') . '.');
        return self::SUCCESS;
    }
}
