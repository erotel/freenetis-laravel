<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPPoE přihlašovací údaje per PŘÍPOJKA (iface) — NE per člen. Zdroj pro RADIUS
 * views (radcheck_pppoe_v / radreply_pppoe_v) při přechodu MAC/IP → PPPoE (NIS2).
 *
 *  - iface_id (PK): jeden credential na přípojné místo → přesně 1 IP + 1 /56.
 *  - username: variabilní symbol člena ({vs}, u víc přípojek {vs}-2, -3 …).
 *  - secret: heslo v CLEARTEXTu — RADIUS musí umět CHAP/MS-CHAPv2, což vyžaduje
 *    znalost hesla v otevřené podobě (view vrací Cleartext-Password). Ochrana
 *    at-rest = šifrování disku/DB, ne aplikační Crypt (ten by view nepřečetl).
 *    Bezpečnost stojí na SÍLE hesla (varsymbol jako username je uhodnutelný).
 *
 * Guard hasTable: na .59 už existuje pilotní tabulka (ruční), migrace ji nepřepíše.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pppoe_secrets')) {
            return;
        }
        Schema::create('pppoe_secrets', function (Blueprint $table) {
            $table->unsignedInteger('iface_id')->primary();
            $table->string('username', 64)->unique();
            $table->string('secret', 64);
            $table->boolean('enabled')->default(true);
            // Bez timestamps — shoda s pilotní tabulkou na .59, kterou čtou RADIUS views.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pppoe_secrets');
    }
};
