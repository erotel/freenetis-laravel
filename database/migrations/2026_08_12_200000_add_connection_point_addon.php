<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dodatek ke smlouvě „přípojné místo" (contract_addons, type='connection_point').
 *
 * Dokumentuje placené přípojné místo (povolenou podsíť s rychlostí a cenou).
 * Znovupoužívá sloupce service_name (název místa / podsítě), service_price
 * (cena), service_action (add/remove) a effective_date; nový sloupec
 * `place_speed_name` drží snímek rychlosti místa. download_tokens.file_type
 * dostane hodnotu 'connection_point_addon'.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('contracts')->table('contract_addons', function (Blueprint $table) {
            $table->string('place_speed_name', 100)->nullable()->after('service_action');
        });

        DB::connection('contracts')->statement(
            "ALTER TABLE download_tokens MODIFY file_type " .
            "ENUM('contract','termination','addon','tariff_addon','service_addon','connection_point_addon') NOT NULL"
        );
    }

    public function down(): void
    {
        Schema::connection('contracts')->table('contract_addons', function (Blueprint $table) {
            $table->dropColumn('place_speed_name');
        });

        DB::connection('contracts')->statement(
            "ALTER TABLE download_tokens MODIFY file_type " .
            "ENUM('contract','termination','addon','tariff_addon','service_addon') NOT NULL"
        );
    }
};
