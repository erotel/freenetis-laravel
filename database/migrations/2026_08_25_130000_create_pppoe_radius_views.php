<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RADIUS views pro PPPoE (čte je FreeRADIUS modul sql_pppoe přes uživatele
 * radius_ro; views jsou SQL SECURITY DEFINER, takže radius_ro nepotřebuje práva
 * na podkladové tabulky). Reprodukovatelné pro nasazení (dosud ruční na .59).
 *
 *  - radcheck_pppoe_v:  username → Cleartext-Password
 *  - radreply_pppoe_v:  username → Framed-IP-Address (+ Delegated-IPv6-Prefix)
 *
 * Zdroje: pppoe_secrets (schválené přípojky, keyed na iface_id) UNION čekající
 * žádosti o připojení (connection_requests.state=0) — díky tomu RADIUS přidělí
 * IP z žádosti pod novým credentialem UŽ PŘED schválením (captive zůstává, dokud
 * nevznikne ip_addresses řádek při schválení). Po schválení žádost přejde do
 * state=2 a mizí z union → obsluhuje ji pppoe_secrets (překlopený credential).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE OR REPLACE VIEW radcheck_pppoe_v AS
            SELECT p.iface_id AS id, p.username AS username, 'Cleartext-Password' AS attribute, ':=' AS op, p.secret AS value
              FROM pppoe_secrets p WHERE p.enabled = 1
            UNION ALL
            SELECT -cr.id AS id, cr.pppoe_username AS username, 'Cleartext-Password' AS attribute, ':=' AS op, cr.pppoe_secret AS value
              FROM connection_requests cr
             WHERE cr.state = 0 AND cr.pppoe_username IS NOT NULL AND cr.pppoe_secret IS NOT NULL
        ");

        DB::statement("
            CREATE OR REPLACE VIEW radreply_pppoe_v AS
            SELECT p.iface_id*10+1 AS id, p.username AS username, 'Framed-IP-Address' AS attribute, '=' AS op, ip.ip_address AS value
              FROM pppoe_secrets p JOIN ip_addresses ip ON (ip.iface_id=p.iface_id AND ip.gateway=0 AND ip.ip_address LIKE '10.%')
             WHERE p.enabled=1
            UNION ALL
            SELECT p.iface_id*10+2 AS id, p.username AS username, 'Delegated-IPv6-Prefix' AS attribute, '=' AS op, ip6.ip_address AS value
              FROM pppoe_secrets p JOIN ip6_addresses ip6 ON (ip6.iface_id=p.iface_id AND ip6.ip_address LIKE '%/%')
             WHERE p.enabled=1
            UNION ALL
            SELECT -cr.id AS id, cr.pppoe_username AS username, 'Framed-IP-Address' AS attribute, '=' AS op, cr.ip_address AS value
              FROM connection_requests cr
             WHERE cr.state=0 AND cr.pppoe_username IS NOT NULL AND cr.ip_address IS NOT NULL AND cr.ip_address LIKE '10.%'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS radreply_pppoe_v');
        DB::statement('DROP VIEW IF EXISTS radcheck_pppoe_v');
    }
};
