<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mění invoices.invoice_nr z DOUBLE NOT NULL na BIGINT UNSIGNED NOT NULL
 * a přidává UNIQUE index.
 *
 * Reálné hodnoty mají formát YYYYNNNNN (max 9 číslic), vejdou se i do INT,
 * ale BIGINT dáváme jako proti budoucímu refaktoru a kvůli konzistenci.
 *
 * Před přepnutím UNIQUE migrace ověří, že v tabulce nejsou duplicitní
 * invoice_nr (aktuálně nejsou — viz historická kontrola). Pokud by nějaké
 * vznikly mezi tímhle a doby migrace, migrace zahodí s hláškou a duplicity
 * musí být přečíslovány ručně, jako u dobropisů.
 */
return new class extends Migration {
    public function up(): void
    {
        $dupes = DB::table('invoices')
            ->select('invoice_nr', DB::raw('COUNT(*) as c'))
            ->groupBy('invoice_nr')
            ->having('c', '>', 1)
            ->get();

        if ($dupes->isNotEmpty()) {
            $list = $dupes->map(fn ($r) => "{$r->invoice_nr} ({$r->c}×)")->implode(', ');
            throw new \RuntimeException(
                "Nelze přidat UNIQUE — v invoices jsou duplicitní invoice_nr: {$list}. "
                . "Nejdřív opravte ručně, pak migraci spusťte znovu."
            );
        }

        // Doctrine DBAL by si na DOUBLE→BIGINT mohl stěžovat, takže raw SQL.
        DB::statement('ALTER TABLE invoices MODIFY invoice_nr BIGINT UNSIGNED NOT NULL');

        Schema::table('invoices', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->unique('invoice_nr', 'uniq_invoice_nr');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->dropUnique('uniq_invoice_nr');
        });

        DB::statement('ALTER TABLE invoices MODIFY invoice_nr DOUBLE NOT NULL');
    }
};
