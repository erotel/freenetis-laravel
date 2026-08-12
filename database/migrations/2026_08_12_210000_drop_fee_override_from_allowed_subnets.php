<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zrušení ruční částky za přípojné místo. Cena placeného místa se nově bere
 * VŽDY z jeho vlastní rychlosti (speed_classes.price přes speed_class_id).
 * Účtovat lze jen místo s vlastní rychlostí (ne zděděnou od člena).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('allowed_subnets', function (Blueprint $table) {
            $table->dropColumn('fee_override');
        });
    }

    public function down(): void
    {
        Schema::table('allowed_subnets', function (Blueprint $table) {
            $table->decimal('fee_override', 10, 2)->nullable()->after('charged');
        });
    }
};
