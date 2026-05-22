<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Přidává UNIQUE index na pohoda_refund_queue.doc_number.
 *
 * Pojistka proti duplicitním číslům dobropisů. Předpoklad: před spuštěním
 * migrace nejsou v tabulce duplicity (jinak migrace skončí "Duplicate entry"
 * chybou — to je záměr, ne potichu polknout problém).
 *
 * Pro kontrolu před migrací:
 *   SELECT doc_number, COUNT(*) c FROM pohoda_refund_queue
 *   GROUP BY doc_number HAVING c > 1;
 */
return new class extends Migration {
    public function up(): void
    {
        $dupes = DB::table('pohoda_refund_queue')
            ->select('doc_number', DB::raw('COUNT(*) as c'))
            ->groupBy('doc_number')
            ->having('c', '>', 1)
            ->get();

        if ($dupes->isNotEmpty()) {
            $list = $dupes->map(fn ($r) => "{$r->doc_number} ({$r->c}×)")->implode(', ');
            throw new \RuntimeException(
                "Nelze přidat UNIQUE — v pohoda_refund_queue jsou duplicitní doc_number: {$list}. "
                . "Nejdřív opravte ručně, pak migraci spusťte znovu."
            );
        }

        Schema::table('pohoda_refund_queue', function (Blueprint $table) {
            $table->dropIndex('idx_doc_number');
            $table->unique('doc_number', 'uniq_doc_number');
        });
    }

    public function down(): void
    {
        Schema::table('pohoda_refund_queue', function (Blueprint $table) {
            $table->dropUnique('uniq_doc_number');
            $table->index('doc_number', 'idx_doc_number');
        });
    }
};
