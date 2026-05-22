<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Přidává sloupce pro stav exportu faktury do Pohody (symetricky s
 * pohoda_refund_queue.status). Nově generované faktury vznikají s
 * pohoda_status='new' (default), exportInvoices() je po vyrobení XML
 * hromadně překlopí na 'exported'.
 *
 * Backfill: všechny existující faktury s date_inv <= 2026-04-30 značíme
 * jako už exportované (Pohoda je má). Květnové+ záměrně zůstávají 'new'
 * — pohoda_export_last_sent='2026-04', takže ještě nešly.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('pohoda_status', 16)->default('new')->after('note');
            $table->dateTime('pohoda_exported_at')->nullable()->after('pohoda_status');
            $table->index('pohoda_status', 'idx_pohoda_status');
        });

        DB::table('invoices')
            ->where('date_inv', '<=', '2026-04-30')
            ->update([
                'pohoda_status'      => 'exported',
                'pohoda_exported_at' => DB::raw("COALESCE(date_inv, NOW())"),
            ]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_pohoda_status');
            $table->dropColumn(['pohoda_status', 'pohoda_exported_at']);
        });
    }
};
