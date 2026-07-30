<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot zvýhodněné (individuální) ceny přímo do smlouvy. `price` drží
 * základní cenu dle tarifu; nové sloupce drží slevu a do kdy platí, aby už
 * podepsaná smlouva zůstala neměnná i po pozdější změně poplatku člena.
 *
 * Běží na 'contracts' connection (contractsdb — DB smlouvy app). Nové smlouvy
 * se renderují ve FreenetISu (Contracts\PdfService), takže sloupce využije
 * tenhle app; legacy renderer smlouvy app je nepotřebuje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('contracts')->table('contract_parties', function (Blueprint $table) {
            $table->decimal('price_after_discount', 10, 2)->nullable()->after('price');
            $table->date('discount_until')->nullable()->after('price_after_discount');
        });
    }

    public function down(): void
    {
        Schema::connection('contracts')->table('contract_parties', function (Blueprint $table) {
            $table->dropColumn(['price_after_discount', 'discount_until']);
        });
    }
};
