<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apply-on-sign u tarifního dodatku: cílový tarif se ukládá jako NÁVRH do
 * dodatku (`new_speed_class_id`) a na člena (`members.speed_class_id`) se
 * propíše teprve při podpisu dodatku (ContractService::signTariffAddon).
 * Dosud dodatek nesl jen denormalizovaný snímek (jméno/cena) bez cílového ID,
 * takže neměl podle čeho tarif po podpisu aplikovat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('contracts')->table('contract_addons', function (Blueprint $table) {
            $table->unsignedInteger('new_speed_class_id')->nullable()->after('new_price_after_discount');
        });
    }

    public function down(): void
    {
        Schema::connection('contracts')->table('contract_addons', function (Blueprint $table) {
            $table->dropColumn('new_speed_class_id');
        });
    }
};
