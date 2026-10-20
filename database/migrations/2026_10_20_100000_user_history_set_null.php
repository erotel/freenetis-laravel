<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Smazání uživatele nesmí mazat historii, kterou zanechal: komentáře u členů,
 * odeslané SMS, přihlášení (NIS2 log), legacy hlasování / úkoly / interní zprávy.
 * Cizí klíče na users(id) z ON DELETE CASCADE (votes: bez akce → smazání
 * hlasujícího padalo) na ON DELETE SET NULL; sloupce nullable. Autora pak
 * UI ukáže jako „smazaný uživatel“; smazání samotného uživatele je v auditu.
 * Idempotentní (FK dohledá podle tabulky + sloupce).
 */
return new class extends Migration
{
    private const COLUMNS = [
        ['comments', 'user_id', ''],
        ['sms_messages', 'user_id', ''],
        ['login_logs', 'user_id', ''],
        ['votes', 'user_id', "COMMENT 'id of voter'"],
        ['jobs', 'user_id', ''],
        ['job_reports', 'user_id', ''],
        ['requests', 'user_id', ''],
        ['mail_messages', 'from_id', ''],
        ['mail_messages', 'to_id', ''],
    ];

    private function fk(string $table, string $column): ?object
    {
        return DB::selectOne('
            SELECT k.CONSTRAINT_NAME AS name, r.DELETE_RULE AS rule
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.COLUMN_NAME = ? AND k.REFERENCED_TABLE_NAME = \'users\'', [$table, $column]);
    }

    private function relink(string $table, string $column, string $comment, bool $nullable, string $rule): void
    {
        $fk = $this->fk($table, $column);
        if (!$fk || $fk->rule === $rule) {
            return;
        }
        DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fk->name}`");
        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` INT(11) " . ($nullable ? 'NULL DEFAULT NULL' : 'NOT NULL') . " {$comment}");
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$fk->name}` FOREIGN KEY (`{$column}`) REFERENCES `users` (`id`) ON DELETE {$rule}");
    }

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $column, $comment]) {
            $this->relink($table, $column, $comment, true, 'SET NULL');
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as [$table, $column, $comment]) {
            if (DB::table($table)->whereNull($column)->exists()) {
                continue;   // osiřelé řádky by NOT NULL neprošly — nechat SET NULL
            }
            $this->relink($table, $column, $comment, false, $table === 'votes' ? 'NO ACTION' : 'CASCADE');
        }
    }
};
