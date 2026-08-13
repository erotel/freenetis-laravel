<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail (NIS2 / ZoKB): „kdo / co / kdy" pro změny v systému.
 *
 * Legacy Kohana FreenetIS logoval do tabulky `logs`, ale při migraci na Laravel
 * se zápis vypnul (poslední řádek 2026-05-13) a tabulka navíc přestala mít
 * partition pro nová data. Zakládáme čistý moderní audit v `audit_logs`;
 * legacy `logs` necháváme zmrazenou jako historii.
 *
 * Tabulka je RANGE-COLUMNS partitioning po měsících kvůli:
 *  - retenci (drop starých partitions je okamžitý, bez DELETE),
 *  - výkonu při růstu (audit roste rychle).
 * Prázdná partition `p_max` (MAXVALUE) je pojistka: INSERT nikdy nespadne na
 * „no partition for value", i kdyby údržbový cron vynechal běh. Údržbu
 * (přidání budoucích měsíců, drop starých) dělá `audit:maintain-partitions`.
 *
 * MySQL/MariaDB omezení: partitioning column musí být v každém UNIQUE/PRIMARY
 * klíči. Proto tabulka nemá PRIMARY KEY — jen `KEY id (id)`, což MariaDB pro
 * AUTO_INCREMENT stačí (stejný pattern jako legacy `logs`).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        // Aktuální měsíc + buffer budoucích měsíců, pak MAXVALUE pojistka.
        $buffer = max(2, (int) config('audit.future_buffer_months', 2));
        $base   = Carbon::now()->startOfMonth();

        $parts = [];
        for ($i = 0; $i <= $buffer; $i++) {
            $month    = $base->copy()->addMonths($i);
            $name     = 'p_' . $month->format('Y_m');
            $boundary = $month->copy()->addMonth()->format('Y-m-01');
            $parts[]  = "  PARTITION {$name} VALUES LESS THAN ('{$boundary}')";
        }
        $parts[] = "  PARTITION p_max VALUES LESS THAN (MAXVALUE)";
        $partitionSql = implode(",\n", $parts);

        DB::statement("
            CREATE TABLE `audit_logs` (
                `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `occurred_at`    DATETIME       NOT NULL,
                `user_id`        INT            NULL,
                `ip_address`     VARCHAR(45)    NULL,
                `action`         VARCHAR(30)    NOT NULL,
                `auditable_type` VARCHAR(100)   NOT NULL,
                `auditable_id`   BIGINT         NULL,
                `old_values`     JSON           NULL,
                `new_values`     JSON           NULL,
                KEY `id` (`id`),
                KEY `idx_auditable` (`auditable_type`, `auditable_id`),
                KEY `idx_user` (`user_id`),
                KEY `idx_occurred` (`occurred_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            PARTITION BY RANGE COLUMNS(`occurred_at`) (
            {$partitionSql}
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
