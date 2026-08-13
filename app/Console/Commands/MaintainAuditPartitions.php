<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Údržba měsíčních partitions tabulky `audit_logs`:
 *  1) dopředu vytvoří chybějící budoucí měsíce (aby INSERT nikdy nespadl),
 *  2) dropne partitions starší než `audit.retention_months` (retence NIS2/ZoKB).
 *
 * Přidávání se dělá REORGANIZE prázdné `p_max` (MAXVALUE) — je to levné, protože
 * p_max je díky předstihu vždy prázdná. Drop starých partitions je okamžitý
 * (žádné DELETE nad miliony řádků).
 *
 * Idempotentní — bezpečné spouštět opakovaně. Plánováno denně.
 */
class MaintainAuditPartitions extends Command
{
    protected $signature = 'audit:maintain-partitions {--dry-run : Jen vypsat, co by se stalo}';

    protected $description = 'Udržuje měsíční partitions audit_logs (budoucí měsíce + retence)';

    public function handle(): int
    {
        if (!Schema::hasTable('audit_logs')) {
            $this->warn('Tabulka audit_logs neexistuje — přeskočeno.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $buffer = max(2, (int) config('audit.future_buffer_months', 2));

        // Načíst existující dated partitions: name => boundary (Carbon).
        $existing = [];   // 'p_YYYY_MM' => Carbon boundary
        $rows = DB::select("
            SELECT PARTITION_NAME AS name, PARTITION_DESCRIPTION AS descr
            FROM information_schema.PARTITIONS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'audit_logs'
              AND PARTITION_NAME IS NOT NULL
              AND PARTITION_NAME <> 'p_max'
        ");
        foreach ($rows as $r) {
            $bound = trim($r->descr, "'\" ");
            $existing[$r->name] = Carbon::parse($bound);
        }

        // 1) Přidat chybějící budoucí měsíce (aktuální + buffer).
        $added = 0;
        for ($i = 0; $i <= $buffer; $i++) {
            $month    = Carbon::now()->startOfMonth()->addMonths($i);
            $name     = 'p_' . $month->format('Y_m');
            $boundary = $month->copy()->addMonth()->format('Y-m-01');

            if (isset($existing[$name])) {
                continue;
            }

            // Nová partition musí být vyšší než všechny existující dated (jinak by
            // se překrývala). Díky souvislosti měsíců chybí jen ty na horním konci.
            $maxBoundary = empty($existing) ? null : max(array_map(fn($c) => $c->timestamp, $existing));
            if ($maxBoundary !== null && Carbon::parse($boundary)->timestamp <= $maxBoundary) {
                continue; // teoretická mezera uvnitř — REORGANIZE p_max by to neřešil
            }

            $sql = "ALTER TABLE audit_logs REORGANIZE PARTITION p_max INTO ("
                 . "PARTITION {$name} VALUES LESS THAN ('{$boundary}'), "
                 . "PARTITION p_max VALUES LESS THAN (MAXVALUE))";

            if ($dryRun) {
                $this->line("[dry-run] + {$name} (< {$boundary})");
            } else {
                DB::statement($sql);
                $this->info("Přidána partition {$name} (< {$boundary}).");
            }
            $existing[$name] = Carbon::parse($boundary);
            $added++;
        }

        // 2) Retence: dropnout partitions starší než retention_months.
        $dropped = 0;
        $retention = (int) config('audit.retention_months', 24);
        if ($retention > 0) {
            // Partition p_YYYY_MM (boundary = 1. den následujícího měsíce) dropneme,
            // když je celý její měsíc starší než retence: boundary <= cutoff.
            $cutoff = Carbon::now()->startOfMonth()->subMonths($retention);

            foreach ($existing as $name => $boundary) {
                if ($boundary->lessThanOrEqualTo($cutoff)) {
                    if ($dryRun) {
                        $this->line("[dry-run] - {$name} (< {$boundary->format('Y-m-d')}, za retencí {$retention} m.)");
                    } else {
                        DB::statement("ALTER TABLE audit_logs DROP PARTITION {$name}");
                        $this->info("Dropnuta stará partition {$name}.");
                    }
                    $dropped++;
                }
            }
        }

        $this->info("Hotovo. Přidáno: {$added}, dropnuto: {$dropped}" . ($dryRun ? ' (dry-run)' : '') . '.');
        return self::SUCCESS;
    }
}
