<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-konzument detekce změn DHCP konfigurace.
 *
 * Původní model měl na subnetu jediný boolean `dhcp_expired`: první DHCP server,
 * který si stáhne export, flag shodí na 0 a druhý server (např. redundantní
 * static-only MikroTik čtoucí stejné subnety) už změnu nikdy neuvidí — dostane
 * HTTP 204. Řešíme to timestampem poslední změny (`subnets.dhcp_changed_at`) plus
 * high-water-markem každého konzumenta (`dhcp_export_state`). Export pak porovnává
 * "kdy se subnet naposledy změnil" vs "kam se daný klient naposledy dostal", takže
 * každý MikroTik vidí každou změnu právě jednou, nezávisle na ostatních. Sdílený
 * `dhcp_expired` zůstává pro zpětnou kompatibilitu (konzument bez ?client=...).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Mikrosekundová přesnost DATETIME(6) — bez ní hrozí ztráta změny, když
        // změna subnetu a stažení klienta spadnou do stejné vteřiny.
        Schema::table('subnets', function (Blueprint $table) {
            $table->dateTime('dhcp_changed_at', 6)->nullable()->after('dhcp_expired');
        });

        // Baseline pro existující subnety — ať první per-client fetch stáhne aktuální
        // stav (nový klient nemá high-water-mark, takže i tak pulne, ale mít reálný
        // timestamp je čistší než NULL).
        DB::statement('UPDATE subnets SET dhcp_changed_at = NOW(6) WHERE dhcp = 1');

        Schema::create('dhcp_export_state', function (Blueprint $table) {
            $table->string('client', 64)->primary();
            $table->dateTime('exported_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhcp_export_state');
        Schema::table('subnets', function (Blueprint $table) {
            $table->dropColumn('dhcp_changed_at');
        });
    }
};
