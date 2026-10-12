<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `line_id_pending` — DHCP klienti s NEZNÁMÝM circuit-id (Kea bez rezervace).
 * Píše FreeRADIUS site `kea4` na Kea uzlech (sql_acct → .54, radius_acct má
 * SELECT/INSERT/UPDATE); aplikace ji zatím nečte (kandidát na onboarding).
 * Na produkci (.54) vznikla ručně → tady create-if-missing, definice 1:1.
 * Viz app/Domains/Access/INFRASTRUKTURA.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('line_id_pending')) {
            return;
        }
        Schema::create('line_id_pending', function (Blueprint $table) {
            $table->increments('id');
            $table->string('circuit_id_hex', 255);
            $table->string('circuit_id', 255)->nullable();
            $table->string('mac', 64)->nullable();
            $table->string('vendor', 32)->nullable();
            $table->string('device_ident', 128)->nullable();
            $table->string('port', 64)->nullable();
            $table->unsignedInteger('seen_count')->default(1);
            $table->timestamp('first_seen')->nullable();
            $table->timestamp('last_seen')->nullable()->index();
            $table->unique('circuit_id_hex', 'uq_pending');
        });
    }

    public function down(): void
    {
        // Záměrně no-op: tabulku plní FreeRADIUS na produkci.
    }
};
