<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // 1 = člen nezvládl měsíční srážku (kredit < poplatek), automaticky
            // se nestrhne nic a internet se přesměruje (PAYMENT_BLOCKED_MESSAGE).
            // Resetuje se na 0 po dorovnání kreditu (PaymentBackchargeService).
            $table->boolean('payment_blocked')->default(0)->index();

            // Datum prvního přeskočení srážky. Použije se pro:
            //  - dohnání chronologicky od nejstaršího v PaymentBackchargeService
            //  - výpočet "k ukončení" (mark-pending-termination 14. den každého měsíce)
            $table->date('payment_blocked_since')->nullable()->index();

            // 1 = členství je navržené k ukončení (jeden měsíc po vzniku payment_blocked).
            // Nastavuje cron, ne automatický end (rozhodnutí 2B); admin schvaluje ručně
            // ve /members/pending-termination.
            $table->boolean('pending_termination')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['payment_blocked', 'payment_blocked_since', 'pending_termination']);
        });
    }
};
