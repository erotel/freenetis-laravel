<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Umožní proaktivní žádost o připojení bez předem známé IP.
 *
 * Původně `connection_requests` vzniká jen z konkrétního už připojeného zařízení
 * (neznámé zařízení detekované přes SNMP → známá IP/subnet/MAC). Nově smí zákazník
 * podat žádost i proaktivně z portálu ("chci další zařízení"), kde IP/subnet/MAC
 * ještě neexistují — technik je doplní až při schválení (create-from-cr formulář
 * má tyto hodnoty jen jako předvyplnění). Proto uvolňujeme NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE connection_requests MODIFY ip_address varchar(39) NULL DEFAULT NULL");
        DB::statement("ALTER TABLE connection_requests MODIFY subnet_id int(11) NULL DEFAULT NULL");
        DB::statement("ALTER TABLE connection_requests MODIFY mac_address varchar(17) NULL DEFAULT NULL");
    }

    public function down(): void
    {
        // Zpět na NOT NULL — případné proaktivní žádosti bez IP/MAC by zúžení
        // rozbilo, proto je nejdřív doplníme placeholderem.
        DB::table('connection_requests')->whereNull('ip_address')->update(['ip_address' => '0.0.0.0']);
        DB::table('connection_requests')->whereNull('mac_address')->update(['mac_address' => '00:00:00:00:00:00']);
        $anySubnet = DB::table('subnets')->min('id') ?? 0;
        DB::table('connection_requests')->whereNull('subnet_id')->update(['subnet_id' => $anySubnet]);

        DB::statement("ALTER TABLE connection_requests MODIFY ip_address varchar(39) NOT NULL");
        DB::statement("ALTER TABLE connection_requests MODIFY subnet_id int(11) NOT NULL");
        DB::statement("ALTER TABLE connection_requests MODIFY mac_address varchar(17) NOT NULL");
    }
};
