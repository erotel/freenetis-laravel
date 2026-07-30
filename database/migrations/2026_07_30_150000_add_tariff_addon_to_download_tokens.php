<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Umožnit jednorázový download podepsaného dodatku „změna tarifu".
 * download_tokens váže na contract_id; dodatek potřebuje ještě addon_id
 * (contract má víc dodatků). + nová hodnota enumu file_type.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('contracts')->statement(
            "ALTER TABLE download_tokens MODIFY file_type ENUM('contract','termination','addon','tariff_addon') NOT NULL"
        );
        if (!Schema::connection('contracts')->hasColumn('download_tokens', 'addon_id')) {
            DB::connection('contracts')->statement(
                "ALTER TABLE download_tokens ADD COLUMN addon_id INT UNSIGNED NULL AFTER contract_id"
            );
        }
    }

    public function down(): void
    {
        if (Schema::connection('contracts')->hasColumn('download_tokens', 'addon_id')) {
            DB::connection('contracts')->statement("ALTER TABLE download_tokens DROP COLUMN addon_id");
        }
        DB::connection('contracts')->statement(
            "ALTER TABLE download_tokens MODIFY file_type ENUM('contract','termination','addon') NOT NULL"
        );
    }
};
