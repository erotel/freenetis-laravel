<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMS kód k dodatku platil pro celou smlouvu — kód poslaný k jednomu dodatku
 * podepsal jiný dodatek téže smlouvy. `addon_id` = contract_addons.id,
 * NULL = starý dodatek (contracts.addon).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('contracts')->table('contract_addon_otps', function (Blueprint $table) {
            $table->unsignedInteger('addon_id')->nullable()->after('contract_id');
            $table->index(['contract_id', 'addon_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('contracts')->table('contract_addon_otps', function (Blueprint $table) {
            $table->dropIndex(['contract_id', 'addon_id']);
            $table->dropColumn('addon_id');
        });
    }
};
