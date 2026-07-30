<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ceníková cena tarifu. speed_classes = rychlostní třída (QoS), nově nese
 * i cenu, aby fungoval třístupňový resolver poplatku:
 *   individuální (members_fees) → cena tarifu (speed_classes.price) → základní (default_fee_member_type).
 *
 * Sémantika:
 *   NULL = tarif nemá cenu → propadne na základní poplatek.
 *   0    = tarif zdarma → strhne se 0 (osvobozeno), NEpropadá na základní.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speed_classes', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('u_rate');
        });
    }

    public function down(): void
    {
        Schema::table('speed_classes', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
