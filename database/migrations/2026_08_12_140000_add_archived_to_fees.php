<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archivační příznak pro tarify/poplatky (`fees`).
 *
 * Umožňuje SKRÝT nepoužívané legacy tarify z nabídky při přiřazování poplatku
 * členovi (a z výchozího výpisu tarifů), aniž by se cokoli mazalo — poplatek i
 * jeho historická přiřazení v `members_fees` zůstávají netknuté. Bezpečná,
 * plně vratná alternativa k mazání (které by přes ON DELETE CASCADE smazalo i
 * historii srážek).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            $table->boolean('archived')->default(false)->after('readonly');
        });
    }

    public function down(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            $table->dropColumn('archived');
        });
    }
};
