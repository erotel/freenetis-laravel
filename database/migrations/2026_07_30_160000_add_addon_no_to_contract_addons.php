<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pořadové číslo dodatku ("DODATEK č. X"). Snapshot na řádku, ať je na
 * podepsaném dodatku neměnné. Počítá se při vytvoření (navazuje i na legacy
 * nulový-tarif addon, který je „č. 1").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('contracts')->table('contract_addons', function (Blueprint $table) {
            $table->unsignedInteger('addon_no')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::connection('contracts')->table('contract_addons', function (Blueprint $table) {
            $table->dropColumn('addon_no');
        });
    }
};
