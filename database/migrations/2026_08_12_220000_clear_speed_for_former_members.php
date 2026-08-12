<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jednorázový úklid: bývalí členové/zákazníci (typ 15/16) → rychlost „žádná"
 * (members.speed_class_id = NULL). Nově se to děje při ukončení automaticky
 * (endMembership, destroy, cron RedirectFormerMembers) — tady doháníme historii.
 *
 * Vedlejší efekt (žádoucí): počty u tříd rychlosti (/speed-classes) pak
 * odrážejí jen aktivní členy; bývalí členové z QoS exportu vypadnou.
 * down() je no-op — původní rychlosti bývalých členů neznáme.
 */
return new class extends Migration {
    public function up(): void
    {
        $cleared = DB::table('members')
            ->whereIn('type', [15, 16])
            ->whereNotNull('speed_class_id')
            ->update(['speed_class_id' => null]);

        echo "  Vynulována rychlost u bývalých členů: {$cleared}" . PHP_EOL;
    }

    public function down(): void
    {
        // Původní rychlosti bývalých členů nelze obnovit.
    }
};
