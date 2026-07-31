<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy typ poplatku „penalty" (Pokuta, enum_types id 39) nevyužíváme.
 * Recyklujeme ho na „Dodatečné služby" (např. poplatek za veřejnou IP),
 * které se strhávají měsíčně jako samostatná položka (transfer type 6).
 *
 * SQL srážky/backcharge matchují na LOWER(enum_types.value) = 'additional service',
 * proto přejmenováváme i hodnotu, ne jen český label ve Fee::typeLabels().
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('enum_types')
            ->where('id', 39)
            ->where('value', 'penalty')
            ->update(['value' => 'additional service']);
    }

    public function down(): void
    {
        DB::table('enum_types')
            ->where('id', 39)
            ->where('value', 'additional service')
            ->update(['value' => 'penalty']);
    }
};
