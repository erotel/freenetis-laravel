<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fáze A dodatku „dodatečná služba" (např. veřejná IP). Reuse tabulky
 * contract_addons — nový type='additional_service' vedle 'tariff_change'.
 * Přidání služby se podepisuje SMS OTP (fáze B), zrušení vydává poskytovatel
 * bez OTP (fáze C). Číslování dodatků (addon_no) běží společně napříč typy.
 *
 * + rozšíření download_tokens.file_type o 'service_addon' pro jednorázový
 *   download podepsaného dodatku služby.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('contracts')->table('contract_addons', function (Blueprint $table) {
            $table->string('service_name', 150)->nullable()->after('discount_until');
            $table->decimal('service_price', 10, 2)->nullable()->after('service_name');
            $table->string('service_action', 10)->nullable()->after('service_price'); // add|remove
        });

        DB::connection('contracts')->statement(
            "ALTER TABLE download_tokens MODIFY file_type ENUM('contract','termination','addon','tariff_addon','service_addon') NOT NULL"
        );
    }

    public function down(): void
    {
        Schema::connection('contracts')->table('contract_addons', function (Blueprint $table) {
            $table->dropColumn(['service_name', 'service_price', 'service_action']);
        });

        DB::connection('contracts')->statement(
            "ALTER TABLE download_tokens MODIFY file_type ENUM('contract','termination','addon','tariff_addon') NOT NULL"
        );
    }
};
