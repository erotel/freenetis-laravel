<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platba per přípojné místo (povolená podsíť) — fáze B.
 *
 * `charged`      = zda se za místo účtuje měsíční poplatek (default 0 =
 *                  grandfathering: stávající místa jsou zdarma, nikomu se bill
 *                  nemění).
 * `fee_override` = ruční měsíční částka za místo. NULL = použije se cena
 *                  rychlosti místa (speed_classes.price přes speed_class_id),
 *                  jinak rychlosti člena.
 *
 * Účtuje se každá charged podsíť (bez ohledu na enabled — platbu řídí admin,
 * zákazník ji vypnutím neobejde); sčítá se do měsíční částky člena vedle
 * tarifu a dodatečných služeb (transfer type 6).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('allowed_subnets', function (Blueprint $table) {
            $table->boolean('charged')->default(false)->after('speed_class_id');
            $table->decimal('fee_override', 10, 2)->nullable()->after('charged');
        });
    }

    public function down(): void
    {
        Schema::table('allowed_subnets', function (Blueprint $table) {
            $table->dropColumn(['charged', 'fee_override']);
        });
    }
};
