<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `line_ids.circuit_id` / `remote_id` = VARBINARY(255), remote NOT NULL DEFAULT ''
 * — stav produkce (.54, ručně). Option82 circuit-/remote-id jsou surové bajty
 * (remote-id typicky MAC switche/OLT); RADIUS views je párují přes HEX(username)
 * a *_kea4_v klíčuje CONCAT(remote_id, circuit_id). VARCHAR utf8mb4 by binární
 * bajty odmítl/poškodil a porovnával by podle collation (case-insensitive).
 *
 * Tady se jen dorovnávají ostatní instance → na .54 no-op.
 * Viz app/Domains/Access/INFRASTRUKTURA.md.
 */
return new class extends Migration
{
    private function columnType(string $column): ?string
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'line_ids')
            ->where('column_name', $column)
            ->value('column_type');
    }

    private function remoteIsStrict(): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'line_ids')
            ->where('column_name', 'remote_id')
            ->where('is_nullable', 'NO')
            ->exists();
    }

    public function up(): void
    {
        if ($this->columnType('circuit_id') === 'varbinary(255)'
            && $this->columnType('remote_id') === 'varbinary(255)'
            && $this->remoteIsStrict()) {
            return;
        }

        // NULL remote = '' (identita bez remote-id); jinak by NOT NULL selhal.
        // Duplicitu to vyrobit nemůže jen tehdy, když vedle (c, NULL) není (c, '') —
        // takovou dvojici by unique index dřív pustil, proto ji odmítneme nahlas.
        $clash = DB::table('line_ids as a')
            ->join('line_ids as b', 'a.circuit_id', '=', 'b.circuit_id')
            ->whereNull('a.remote_id')->where('b.remote_id', '')
            ->count();
        if ($clash > 0) {
            throw new RuntimeException("line_ids: {$clash}× (circuit, NULL) i (circuit, '') — vyřeš ručně před migrací.");
        }
        DB::table('line_ids')->whereNull('remote_id')->update(['remote_id' => '']);

        DB::statement("ALTER TABLE line_ids
            MODIFY circuit_id VARBINARY(255) NOT NULL,
            MODIFY remote_id VARBINARY(255) NOT NULL DEFAULT ''");
    }

    public function down(): void
    {
        // Záměrně no-op: návrat na VARCHAR by poškodil binární remote-id.
    }
};
