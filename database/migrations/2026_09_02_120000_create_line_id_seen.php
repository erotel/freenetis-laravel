<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staging tabulka pro self-learning line-id z RADIUS accountingu (fáze B).
 * FreeRADIUS (uživatel radius_acct) do ní v accounting sekci zapisuje každý
 * viděný pár (circuit-id, MAC) — MK posílá Acct-Start s option82 i u statických
 * leasů, takže se plní PRŮBĚŽNĚ pro všechny aktivní zákazníky, ještě než se
 * subnet přepne na ipoe.
 *
 * `lineid:sync` command pak páruje MAC → iface a překlápí známé do `line_ids`
 * (source='accounting'); nespárované (neznámý MAC / neregistrovaná přípojka)
 * zůstávají zde s reconciled=0 = kandidáti na onboarding.
 *
 * circuit_id_hex = hex tak, jak dorazí z RADIUS xlatu `%{ADSL-Agent-Circuit-Id}`
 * (octets → '0x4769..'); command ho dekóduje na ASCII (UNHEX).
 *
 * Grant (infra, mimo migraci): GRANT INSERT,UPDATE,SELECT ON freenetis.line_id_seen
 * TO 'radius_acct'@'localhost';
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('line_id_seen')) {
            return;
        }
        Schema::create('line_id_seen', function (Blueprint $table) {
            $table->increments('id');
            $table->string('circuit_id_hex', 255);        // '0x4769..' z RADIUSu
            $table->string('mac', 32);                     // %{User-Name} = klient MAC
            $table->unsignedInteger('seen_count')->default(1);
            $table->boolean('reconciled')->default(false); // 1 = překlopeno do line_ids
            $table->timestamp('last_seen')->nullable();
            $table->unique(['circuit_id_hex', 'mac'], 'uq_seen');
            $table->index('reconciled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_id_seen');
    }
};
