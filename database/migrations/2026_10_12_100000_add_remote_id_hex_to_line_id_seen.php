<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Option82 remote-id (sub-2, ident switche) ve staging `line_id_seen` — identita
 * line-id = (circuit_id, remote_id), viz 99fa4c2 a LineIdSyncService.
 *
 * Na produkci (.54) sloupec přidán ručně při nasazení Kea (plní ho FreeRADIUS na
 * Kea uzlech .230.22/.23); tahle migrace jen srovnává ostatní instance → na .54
 * no-op (hasColumn). Bez sloupce `lineid:sync` padá na Unknown column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('line_id_seen', 'remote_id_hex')) {
            return;
        }
        Schema::table('line_id_seen', function (Blueprint $table) {
            $table->string('remote_id_hex', 255)->nullable()->after('circuit_id_hex');
        });
    }

    public function down(): void
    {
        // Záměrně no-op: na produkci sloupec předchází migraci a plní ho Kea uzly.
    }
};
