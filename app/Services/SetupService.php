<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;

/**
 * First-run setup service.
 *
 * Žije od momentu, kdy 02-configure-app.sh napsal storage/app/setup.token,
 * do okamžiku, kdy admin dokončí web wizard. Pak se token soubor smaže
 * a SetupService::isSetupNeeded() trvale vrací false.
 */
class SetupService
{
    private const SYSADMIN_GROUP_ID = 32;

    public function tokenPath(): string
    {
        return storage_path('app/setup.token');
    }

    /**
     * Vrátí true, pokud by se měl wizard zobrazit:
     *   - existuje storage/app/setup.token   AND
     *   - žádný admin v System administrators
     */
    public function isSetupNeeded(): bool
    {
        if (!is_file($this->tokenPath())) {
            return false;
        }

        try {
            $count = DB::table('groups_aro_map')
                ->where('group_id', self::SYSADMIN_GROUP_ID)
                ->count();
            return $count === 0;
        } catch (\Throwable $e) {
            // groups_aro_map ještě nemusí existovat — schema importujeme uvnitř wizardu
            return true;
        }
    }

    public function verifyToken(string $token): bool
    {
        if (!is_file($this->tokenPath())) {
            return false;
        }
        $stored = trim((string) @file_get_contents($this->tokenPath()));
        if ($stored === '' || $token === '') {
            return false;
        }
        return hash_equals($stored, $token);
    }

    /**
     * Importuje uploadnutý SQL dump (volitelně .gz) do main DB.
     * Streamuje data přes pipe — bez ohledu na velikost souboru.
     * Plus: pokud contractsdb je ještě prázdná, naimportuje contracts bootstrap
     *       (ten zpravidla v legacy Kohana dumpu chybí — Laravel ho potřebuje).
     */
    public function importDump(string $absPath, bool $isGzipped): void
    {
        if (!is_file($absPath)) {
            throw new \RuntimeException("Soubor s dumpem nenalezen: $absPath");
        }

        $cmd = $isGzipped
            ? sprintf('gunzip -c %s | mariadb --defaults-file=%s %s',
                escapeshellarg($absPath),
                escapeshellarg($this->mariadbDefaultsFile()),
                escapeshellarg(config('database.connections.mysql.database')))
            : sprintf('mariadb --defaults-file=%s %s < %s',
                escapeshellarg($this->mariadbDefaultsFile()),
                escapeshellarg(config('database.connections.mysql.database')),
                escapeshellarg($absPath));

        $this->runShell($cmd, 1800);

        // Po importu hlavní DB doplníme contracts schema, pokud ještě není.
        // Legacy Kohana FreenetIS contracts neměl, takže typický migrační dump
        // ho nikdy neobsahuje — bez něj padá ContractService po loginu.
        $contractsBootstrap = base_path('scripts/install/sql/contractsdb-bootstrap.sql.gz');
        if (is_file($contractsBootstrap) && $this->isDbEmpty('contracts')) {
            $cmd2 = sprintf('gunzip -c %s | mariadb --defaults-file=%s %s',
                escapeshellarg($contractsBootstrap),
                escapeshellarg($this->contractsMariadbDefaultsFile()),
                escapeshellarg(config('database.connections.contracts.database')));
            $this->runShell($cmd2, 60);
        }
    }

    /**
     * Importuje vestavěný bootstrap z scripts/install/sql/.
     * Skipne import, pokud cílová DB už má tabulky (idempotentní).
     */
    public function importBootstrap(): void
    {
        $main = base_path('scripts/install/sql/freenetis-bootstrap.sql.gz');
        $contracts = base_path('scripts/install/sql/contractsdb-bootstrap.sql.gz');

        if (!is_file($main)) {
            throw new \RuntimeException("Bootstrap dump chybí: $main");
        }

        // Main DB import — jen pokud je prázdná
        if ($this->isDbEmpty('mysql')) {
            $cmd = sprintf('gunzip -c %s | mariadb --defaults-file=%s %s',
                escapeshellarg($main),
                escapeshellarg($this->mariadbDefaultsFile()),
                escapeshellarg(config('database.connections.mysql.database')));
            $this->runShell($cmd, 600);
        }

        // Contracts DB import — taky jen pokud prázdná
        if (is_file($contracts) && $this->isDbEmpty('contracts')) {
            $cmd2 = sprintf('gunzip -c %s | mariadb --defaults-file=%s %s',
                escapeshellarg($contracts),
                escapeshellarg($this->contractsMariadbDefaultsFile()),
                escapeshellarg(config('database.connections.contracts.database')));
            $this->runShell($cmd2, 60);
        }
    }

    private function isDbEmpty(string $connection): bool
    {
        try {
            $count = DB::connection($connection)
                ->select('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE()');
            return ((int) ($count[0]->c ?? 0)) === 0;
        } catch (\Throwable $e) {
            return true; // Nelze ověřit → pojďme zkusit import
        }
    }

    public function runMigrations(): void
    {
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    }

    public function runSeeders(): void
    {
        foreach (['AclGponContractsSeeder', 'AclSmtpExceptionsSeeder'] as $class) {
            try {
                Artisan::call('db:seed', [
                    '--class' => $class,
                    '--force' => true,
                    '--no-interaction' => true,
                ]);
            } catch (\Throwable $e) {
                // Seeder může selhat, pokud řádky už existují — neignorovat,
                // ale ani neshoditr setup. Logovat do logu.
                \Log::warning("Seeder $class: " . $e->getMessage());
            }
        }
    }

    /**
     * Vytvoří admina pro čistou instalaci. Voláno pouze, pokud nebyl uploadnut dump.
     */
    public function createAdmin(
        string $orgName,
        string $login,
        string $password,
        string $name,
        string $surname,
        string $email,
    ): void {
        Artisan::call('freenetis:install', [
            '--org-name' => $orgName,
            '--login'    => $login,
            '--password' => $password,
            '--name'     => $name,
            '--surname'  => $surname,
            '--email'    => $email,
            '--no-interaction' => true,
        ]);

        $output = Artisan::output();
        if (stripos($output, 'už existují') === false && stripos($output, 'vytvořen') === false) {
            // Pokud install nevypsal úspěch, hodíme exception s logem
            throw new \RuntimeException('freenetis:install: ' . trim($output));
        }
    }

    /**
     * email_queues idx_state — ručně přidaný index pro performance, ne-migrované.
     * Idempotent.
     */
    public function ensureEmailQueuesIndex(): void
    {
        try {
            DB::statement("
                SET @x := (SELECT COUNT(*) FROM information_schema.statistics
                           WHERE table_schema = DATABASE() AND table_name = 'email_queues' AND index_name = 'idx_state')
            ");
            DB::statement("SET @sql := IF(@x = 0, 'ALTER TABLE email_queues ADD INDEX idx_state (state)', 'SELECT 1')");
            DB::statement('PREPARE stmt FROM @sql');
            DB::statement('EXECUTE stmt');
            DB::statement('DEALLOCATE PREPARE stmt');
        } catch (\Throwable $e) {
            \Log::warning('ensureEmailQueuesIndex: ' . $e->getMessage());
        }
    }

    /**
     * Cache jen config + view. route:cache z webového FPM kontextu produkuje
     * rozbité cache (URL prefix /freenetis/ pak vrací 405 na auth routách),
     * proto ho z wizardu vynecháváme. Admin si může z CLI spustit
     * `php artisan route:cache` po nasazení.
     */
    public function cacheArtifacts(): void
    {
        foreach (['config:cache', 'view:cache'] as $cmd) {
            try {
                Artisan::call($cmd, ['--no-interaction' => true]);
            } catch (\Throwable $e) {
                \Log::warning("Artisan $cmd: " . $e->getMessage());
            }
        }
    }

    /**
     * Uzavře setup — smaže token soubor. Po tomto wizard vrací 404.
     */
    public function complete(): void
    {
        if (is_file($this->tokenPath())) {
            @unlink($this->tokenPath());
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function runShell(string $cmd, int $timeoutSec): void
    {
        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout($timeoutSec);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Shell command selhal (exit {$process->getExitCode()}): "
                . trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * mariadb klient se autentizuje přes ~/.my.cnf, my generujeme dočasný
     * defaults-file s aktuálními credentials z .env, abychom nemuseli předávat
     * heslo přes command-line (kde by se objevilo v `ps`).
     */
    private function mariadbDefaultsFile(): string
    {
        return $this->writeDefaultsFile(
            (string) config('database.connections.mysql.host'),
            (int) config('database.connections.mysql.port'),
            (string) config('database.connections.mysql.username'),
            (string) config('database.connections.mysql.password'),
            'mysql'
        );
    }

    private function contractsMariadbDefaultsFile(): string
    {
        return $this->writeDefaultsFile(
            (string) config('database.connections.contracts.host'),
            (int) config('database.connections.contracts.port'),
            (string) config('database.connections.contracts.username'),
            (string) config('database.connections.contracts.password'),
            'contracts'
        );
    }

    private function writeDefaultsFile(string $host, int $port, string $user, string $password, string $tag): string
    {
        $file = storage_path("app/.mariadb-{$tag}.cnf");
        $content = "[client]\nhost = {$host}\nport = {$port}\nuser = {$user}\npassword = {$password}\n";
        file_put_contents($file, $content);
        @chmod($file, 0600);
        return $file;
    }
}
