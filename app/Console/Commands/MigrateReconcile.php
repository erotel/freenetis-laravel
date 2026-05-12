<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Smíří `migrations` tabulku se skutečným stavem schématu.
 *
 * Důvod: legacy importy (SetupController::importBootstrap, ručně nahraný produkční dump,
 * jiný server, kde se kdysi migrace pustila bez záznamu) nechávají tabulky existující,
 * ale chybí jim záznamy v `migrations`. `migrate --force` pak padá na duplicate table /
 * duplicate column.
 *
 * Strategie: každá pending migrace se spustí standardně. Pokud projde, záznam do
 * `migrations`. Pokud selže s SQLSTATE 42S01 (table exists), 42S21 (column exists)
 * nebo MySQL chybou 1050/1060/1061/1826, záznam jako already-applied (cílový stav
 * už platí). Jiné chyby probublají. Bez explicitní transakce — MySQL DDL stejně
 * implicit commitne, takže obalování `beginTransaction`/`rollBack` by jen
 * generovalo "no active transaction" chyby.
 *
 * Po doběhnutí jsou všechny pending migrace buď úspěšně aplikované, nebo
 * korektně registrované — následný `migrate --force` udělá no-op.
 */
class MigrateReconcile extends Command
{
    protected $signature   = 'migrate:reconcile {--dry-run : Jen vypiš, co by udělal}';
    protected $description = 'Synchronizuje migrations tabulku se skutečným schématem (idempotent pro legacy DBs).';

    public function handle(): int
    {
        if (!\Schema::hasTable('migrations')) {
            $this->info('migrations table chybí, spouštím migrate:install.');
            $this->call('migrate:install');
        }

        $applied = DB::table('migrations')->pluck('migration')->all();
        $files   = collect(File::files(database_path('migrations')))
            ->map(fn($f) => $f->getFilenameWithoutExtension())
            ->sort()
            ->values();

        $pending = $files->reject(fn($n) => in_array($n, $applied, true))->values();

        if ($pending->isEmpty()) {
            $this->info('Žádné pending migrace. ✓');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $batch  = ((int) DB::table('migrations')->max('batch')) + 1;

        $this->info(sprintf('Pending: %d migrací. Batch by byl: %d.%s', $pending->count(), $batch, $dryRun ? ' [DRY-RUN]' : ''));

        $applied = 0; $registered = 0;

        foreach ($pending as $name) {
            $path = database_path('migrations/' . $name . '.php');
            /** @var \Illuminate\Database\Migrations\Migration $migration */
            $migration = require $path;

            if ($dryRun) {
                // V dry-run nepouštíme up() vůbec — místo toho jen reportneme
                // přibližně co by se stalo. Není to 100% věrné (nezachytí kolize
                // až za prvním statementem), ale stačí to pro orientaci a hlavně
                // dry-run nesmí měnit schéma.
                $this->line("  [would apply] $name <fg=gray>(skutečné kolize zjistí až ostrá spuštění)</>");
                continue;
            }

            try {
                if (method_exists($migration, 'up')) {
                    $migration->up();
                }
                DB::table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
                $this->line("  <fg=green>applied</>     $name");
                $applied++;
            } catch (QueryException $e) {
                if ($this->isAlreadyExistsError($e)) {
                    DB::table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
                    $this->line("  <fg=yellow>registered</>  $name <fg=gray>(schéma už existuje)</>");
                    $registered++;
                } else {
                    $this->error("FAIL na $name: " . $e->getMessage());
                    return self::FAILURE;
                }
            } catch (\Throwable $e) {
                $this->error("FAIL na $name: " . $e->getMessage());
                return self::FAILURE;
            }
        }

        $this->info(sprintf('Hotovo: %d aplikováno, %d registrováno jako already-exists.', $applied, $registered));
        return self::SUCCESS;
    }

    private function isAlreadyExistsError(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        // MySQL: 1050 (table exists), 1060 (column exists), 1061 (key exists), 1826 (foreign key exists)
        if (in_array($driverCode, [1050, 1060, 1061, 1826], true)) return true;
        // Generic SQLSTATE codes
        if (in_array($sqlState, ['42S01', '42S21'], true)) return true;
        // Fallback na text
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'already exists') || str_contains($msg, 'duplicate column');
    }
}
