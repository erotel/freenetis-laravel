<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rychlost per přípojné místo (povolená podsíť).
 *
 * Dosud byla rychlost jen na členovi (`members.speed_class_id`) a QoS ji sdílel
 * přes všechny jeho IP. Nově může mít každá povolená podsíť vlastní rychlost.
 * NULL = zdědí rychlost člena (zpětná kompatibilita — dokud admin nenastaví
 * per-podsíť rychlost, chová se to přesně jako dřív).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('allowed_subnets', function (Blueprint $table) {
            $table->unsignedInteger('speed_class_id')->nullable()->after('subnet_id');
        });
    }

    public function down(): void
    {
        Schema::table('allowed_subnets', function (Blueprint $table) {
            $table->dropColumn('speed_class_id');
        });
    }
};
