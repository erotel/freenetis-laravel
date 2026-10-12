<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identita line-id = (circuit_id, remote_id) — stejný circuit-id na dvou switchích
 * (DCN na sdílené VLAN, TP-Link VLAN 1) jsou dvě přípojky (viz 99fa4c2).
 *
 * Na produkci (.54) index `line_ids_circuit_remote_unique` vznikl ručně; tady se
 * jen srovnávají ostatní instance → na .54 no-op. Složený klíč je volnější než
 * původní unique(circuit_id), takže převod nemůže narazit na duplicitu.
 */
return new class extends Migration
{
    private function hasIndex(string $name): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'line_ids')
            ->where('index_name', $name)
            ->exists();
    }

    public function up(): void
    {
        if ($this->hasIndex('line_ids_circuit_remote_unique')) {
            return;
        }
        Schema::table('line_ids', function (Blueprint $table) {
            $table->unique(['circuit_id', 'remote_id'], 'line_ids_circuit_remote_unique');
        });
        if ($this->hasIndex('line_ids_circuit_id_unique')) {
            Schema::table('line_ids', function (Blueprint $table) {
                $table->dropUnique('line_ids_circuit_id_unique');
            });
        }
    }

    public function down(): void
    {
        // Záměrně no-op: návrat na unique(circuit_id) by na produkci selhal
        // (existují dvojice se stejným circuit-id a různým remote-id).
    }
};
