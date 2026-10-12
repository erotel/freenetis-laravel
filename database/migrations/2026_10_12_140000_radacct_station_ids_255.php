<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * radacct.calledstationid / callingstationid VARCHAR(50) → VARCHAR(255).
 *
 * MikroTik DHCP relaye (10.133.230.2/.3) posílají do accountingu:
 *  - Called-Station-Id  = jméno DHCP serveru = jméno subnetu (běžně 40–50+ znaků),
 *  - Calling-Station-Id = DHCP client-id (IAID+DUID, např. „ff:3e:75:…:b2:99"),
 * delší hodnoty strict sql_mode odmítl (ERROR 1406) → FreeRADIUS na Kea uzlech
 * (sql_acct → .54) od 2026-09-07 zahazoval celé záznamy session (NIS2 log).
 * 50 je jen výchozí hodnota schématu FreeRADIUS. Idempotentní; tabulka
 * radacct jinak pod migrací není (legacy, píše ji jen FreeRADIUS).
 */
return new class extends Migration
{
    private const COLUMNS = ['calledstationid', 'callingstationid'];

    private function narrow(): array
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'radacct')
            ->whereIn('column_name', self::COLUMNS)
            ->where('character_maximum_length', '<', 255)
            ->pluck('column_name')
            ->all();
    }

    public function up(): void
    {
        $narrow = $this->narrow();
        if ($narrow === []) {
            return;
        }
        DB::statement('ALTER TABLE radacct ' . implode(', ', array_map(
            fn ($c) => "MODIFY {$c} VARCHAR(255) NOT NULL DEFAULT ''",
            $narrow
        )));
    }

    public function down(): void
    {
        // Záměrně no-op: zúžení by na delších hodnotách selhalo / ořezalo data.
    }
};
