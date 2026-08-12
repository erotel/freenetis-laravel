<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jednorázový úklid: řádní členové (typ 90) → rychlost „žádná"
 * (members.speed_class_id = NULL). Řádní členové jsou členové sdružení, ne
 * internetoví zákazníci — nemají mít přiřazenou rychlost.
 *
 * Netýká se rychlostí per přípojné místo (allowed_subnets.speed_class_id) —
 * ty zůstávají. down() je no-op (původní rychlosti neznáme).
 */
return new class extends Migration {
    public function up(): void
    {
        $cleared = DB::table('members')
            ->where('type', 90)
            ->whereNotNull('speed_class_id')
            ->update(['speed_class_id' => null]);

        echo "  Vynulována rychlost u řádných členů (typ 90): {$cleared}" . PHP_EOL;
    }

    public function down(): void
    {
        // Původní rychlosti neznáme.
    }
};
