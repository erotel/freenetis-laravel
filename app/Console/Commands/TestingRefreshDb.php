<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Připraví testovací DB pro phpunit — obě, které aplikace používá:
 *   freenetis   → freenetis_test   (database/schema/mysql-schema.sql)
 *   contractsdb → contractsdb_test (database/schema/contracts-schema.sql)
 *
 * Pro každou: smaže v ní všechny tabulky a views, nahraje výchozí schéma z repa
 * (= produkce), doběhnou migrace, které v něm ještě nejsou, a zkopírují se
 * data ze zdrojové DB na stejném serveru (INSERT … SELECT) — testy stojí na
 * referenčních datech (typy, šablony zpráv, nastavení, ACL). Velké logovací
 * tabulky se přeskakují (jen schéma).
 *
 * Testy tak neběží na živých DB (na .59 z freenetis čte RADIUS) a DDL v testu
 * nic nerozbije. Cílové DB musí existovat a končit `_test` (založí je root:
 * CREATE DATABASE freenetis_test; GRANT ALL ON freenetis_test.* TO freenetis@localhost;
 * totéž contractsdb_test pro uživatele z CONTRACTS_DB_USER).
 */
class TestingRefreshDb extends Command
{
    protected $signature = 'testing:refresh-db
        {--target=freenetis_test : testovací DB pro freenetis (musí končit _test)}
        {--contracts-target=contractsdb_test : testovací DB pro smlouvy (musí končit _test)}';

    protected $description = 'Obnoví testovací DB (freenetis + smlouvy): schéma z repa + kopie dat ze živých DB.';

    /** Velké logovací tabulky — kopíruje se jen schéma. */
    public const SKIP_DATA = [
        'email_queues', 'email_queue_attachments', 'log_queues', 'logs', 'sms_messages',
        'radacct', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Na produkci se testovací DB neobnovuje.');
            return self::FAILURE;
        }

        $dbs = [
            // connection => [zdroj, cíl, schéma]
            'mysql'     => [config('database.connections.mysql.database'), (string) $this->option('target'), 'mysql-schema.sql'],
            'contracts' => [config('database.connections.contracts.database'), (string) $this->option('contracts-target'), 'contracts-schema.sql'],
        ];
        foreach ($dbs as [$source, $target]) {
            if (!str_ends_with($target, '_test') || $target === $source) {
                $this->error("Cílová DB „{$target}“ musí končit _test a lišit se od zdroje „{$source}“.");
                return self::FAILURE;
            }
        }

        // Oba connectiony přesměrujeme na testovací DB — migrace, které
        // míří na Schema::connection('contracts'), tak nikdy nesáhnou na živou DB.
        foreach ($dbs as $conn => [, $target]) {
            config(["database.connections.{$conn}.database" => $target]);
            DB::purge($conn);
        }

        foreach ($dbs as $conn => [$source, $target, $schema]) {
            $db = DB::connection($conn);
            $this->info("[{$target}] mažu objekty, nahrávám {$schema}…");
            $this->dropAll($db, $target);
            $db->getSchemaState()->load(database_path("schema/{$schema}"));
        }

        $this->info('Migrace…');
        Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]);
        $this->line(trim(Artisan::output()) ?: '   (žádné nové migrace)');

        foreach ($dbs as $conn => [$source, $target]) {
            $copied = $this->copyData(DB::connection($conn), $source, $target);
            $this->info("[{$target}] {$copied} tabulek s daty z {$source}.");
        }

        $this->scrubSmsCredentials(DB::connection('mysql'));

        return self::SUCCESS;
    }

    /**
     * Nastavení se kopíruje i s přístupy k SMS bráně — test se skutečným
     * OtpService mimo testovací režim by poslal placenou SMS na číslo z testu.
     */
    public static function scrubSmsCredentials(Connection $db): void
    {
        $db->table('config')->where(fn ($q) => $q->where('name', 'like', 'sms\\_password%')->orWhere('name', 'like', 'sms\\_user%'))
            ->update(['value' => '']);
    }

    private function dropAll(Connection $db, string $target): void
    {
        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->select('SELECT TABLE_NAME n, TABLE_TYPE t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$target]) as $o) {
            $db->statement(($o->t === 'VIEW' ? 'DROP VIEW' : 'DROP TABLE') . " IF EXISTS `{$target}`.`{$o->n}`");
        }
        $db->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function copyData(Connection $db, string $source, string $target): int
    {
        $tables = $db->select(
            "SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME <> 'migrations' ORDER BY 1",
            [$target]
        );
        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        // Legacy data mají nulová data (0000-00-00) — strict mód Laravelu by je
        // odmítl; kopírujeme 1:1 jako jsou ve zdroji.
        $db->statement("SET SESSION sql_mode = ''");
        $copied = 0;
        foreach ($tables as $t) {
            if (in_array($t->n, self::SKIP_DATA, true)) {
                continue;
            }
            // jen sloupce, které mají obě strany (zdroj může být o migraci pozadu)
            $cols = array_column($db->select(
                'SELECT a.COLUMN_NAME c FROM information_schema.COLUMNS a
                 JOIN information_schema.COLUMNS b ON b.TABLE_SCHEMA = ? AND b.TABLE_NAME = a.TABLE_NAME AND b.COLUMN_NAME = a.COLUMN_NAME
                 WHERE a.TABLE_SCHEMA = ? AND a.TABLE_NAME = ? ORDER BY a.ORDINAL_POSITION',
                [$source, $target, $t->n]
            ), 'c');
            if ($cols === []) {
                continue;
            }
            $list = '`' . implode('`,`', $cols) . '`';
            $db->statement("INSERT INTO `{$target}`.`{$t->n}` ({$list}) SELECT {$list} FROM `{$source}`.`{$t->n}`");
            $copied++;
        }
        $db->statement('SET FOREIGN_KEY_CHECKS=1');
        return $copied;
    }
}
