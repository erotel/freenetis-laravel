<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IPoE line-id mapování + RADIUS views (fáze B). `line_ids` drží option82
 * circuit-id → iface. FreeRADIUS modul `sql_lineid` (uživatel radius_ro) z
 * příchozího `ADSL-Agent-Circuit-Id` (octets → hex → match přes HEX(circuit_id))
 * vrací:
 *   - radcheck_lineid_v:  Auth-Type := Accept  (rlm_sql MUSÍ dostat řádek, jinak
 *                         hlásí notfound a NEspustí reply query)
 *   - radreply_lineid_v:  Framed-IP-Address z ip_addresses (iface → IP)
 *
 * Obojí je GATED na `subnets.ipoe = 1` → line-id přidělení jede jen na IPoE
 * subnetech; ostatní zůstávají na statických leasech (žádný dopad).
 *
 * Guard hasTable: na .59 může existovat pilotní (ruční) tabulka. Grant
 * `radius_ro` SELECT na views je infra per-MK (jako u pppoe views) — přidat
 * mimo migraci: GRANT SELECT ON freenetis.radreply_lineid_v TO 'radius_ro'@'localhost';
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('line_ids')) {
            Schema::create('line_ids', function (Blueprint $table) {
                $table->increments('id');
                $table->string('circuit_id', 255)->unique();      // raw option82 circuit-id (ASCII)
                $table->string('remote_id', 255)->nullable();       // option82 remote-id (často switch MAC)
                $table->unsignedInteger('iface_id')->index();       // FreenetIS přípojka
                $table->string('vendor', 32)->nullable();           // parsed: huawei/dcn/gpon/mikrotik
                $table->string('device_ident', 128)->nullable();    // parsed: switch/OLT identita
                $table->string('port', 64)->nullable();             // parsed: fyzický port
                $table->string('source', 16)->default('manual');    // bootstrap/accounting/manual
                $table->timestamp('last_seen')->nullable();
                $table->timestamps();
            });
        }

        DB::statement("
            CREATE OR REPLACE VIEW radreply_lineid_v AS
            SELECT l.id AS id, l.circuit_id AS username,
                   'Framed-IP-Address' AS attribute, '=' AS op, ip.ip_address AS value
              FROM line_ids l
              JOIN ip_addresses ip ON ip.iface_id = l.iface_id AND ip.gateway = 0 AND ip.ip_address LIKE '10.%'
              JOIN subnets s ON s.id = ip.subnet_id AND s.dhcp = 1 AND s.ipoe = 1
        ");

        DB::statement("
            CREATE OR REPLACE VIEW radcheck_lineid_v AS
            SELECT MIN(l.id) AS id, l.circuit_id AS username,
                   'Auth-Type' AS attribute, ':=' AS op, 'Accept' AS value
              FROM line_ids l
              JOIN ip_addresses ip ON ip.iface_id = l.iface_id AND ip.gateway = 0 AND ip.ip_address LIKE '10.%'
              JOIN subnets s ON s.id = ip.subnet_id AND s.dhcp = 1 AND s.ipoe = 1
             GROUP BY l.circuit_id
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS radreply_lineid_v');
        DB::statement('DROP VIEW IF EXISTS radcheck_lineid_v');
        Schema::dropIfExists('line_ids');
    }
};
