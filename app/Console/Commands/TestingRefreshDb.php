<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Připraví testovací DB (výchozí `freenetis_test`) pro phpunit:
 *  1. smaže v ní všechny tabulky a views,
 *  2. nahraje výchozí schéma z repa (database/schema/mysql-schema.sql = produkce)
 *     a doběhne migrace, které v něm ještě nejsou,
 *  3. zkopíruje data ze zdrojové DB na stejném serveru (INSERT … SELECT) —
 *     testy stojí na referenčních datech (typy, šablony zpráv, nastavení, ACL),
 *     velké logovací tabulky se přeskakují (jen schéma).
 *
 * Testy tak neběží na živé DB (na .59 z ní čte RADIUS) a DDL v testu nic
 * nerozbije. Cílová DB musí existovat a končit `_test` (založí ji root:
 * CREATE DATABASE freenetis_test; GRANT ALL ON freenetis_test.* TO freenetis@localhost).
 */
class TestingRefreshDb extends Command
{
    protected $signature = 'testing:refresh-db
        {--target=freenetis_test : testovací DB (musí končit _test)}
        {--source= : zdroj dat (výchozí = DB z .env)}';

    protected $description = 'Obnoví testovací DB: schéma z repa + kopie dat ze zdrojové DB.';

    /** Velké logovací tabulky — kopíruje se jen schéma. */
    public const SKIP_DATA = [
        'email_queues', 'email_queue_attachments', 'log_queues', 'logs', 'sms_messages',
        'radacct', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    public function handle(): int
    {
        $target = (string) $this->option('target');
        $source = (string) ($this->option('source') ?: config('database.connections.mysql.database'));

        if (!str_ends_with($target, '_test') || $target === $source) {
            $this->error("Cílová DB „{$target}“ musí končit _test a lišit se od zdroje.");
            return self::FAILURE;
        }
        if (app()->isProduction()) {
            $this->error('Na produkci se testovací DB neobnovuje.');
            return self::FAILURE;
        }

        config(['database.connections.testing_target' => array_merge(
            config('database.connections.mysql'),
            ['database' => $target]
        )]);
        $db = DB::connection('testing_target');

        $this->info("1/3 Mažu objekty v {$target}…");
        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->select('SELECT TABLE_NAME n, TABLE_TYPE t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$target]) as $o) {
            $db->statement(($o->t === 'VIEW' ? 'DROP VIEW' : 'DROP TABLE') . " IF EXISTS `{$o->n}`");
        }

        $this->info('2/3 Schéma z repa + migrace…');
        $db->getSchemaState()->load(database_path('schema/mysql-schema.sql'));
        Artisan::call('migrate', ['--database' => 'testing_target', '--force' => true]);
        $this->line(trim(Artisan::output()) ?: '   (žádné nové migrace)');

        $this->info("3/3 Kopíruju data z {$source}…");
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

        $this->info("Hotovo: {$copied} tabulek s daty, " . count(self::SKIP_DATA) . ' jen se schématem.');
        return self::SUCCESS;
    }
}
