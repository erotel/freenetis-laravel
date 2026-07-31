<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fáze D – snapshot dodatečných služeb do smlouvy. contract_parties je neměnný
 * snímek při podpisu, proto službu (název + cena) i její součet ukládáme sem,
 * aby podepsané PDF zůstalo konzistentní bez ohledu na pozdější změny members_fees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('contracts')->table('contract_parties', function (Blueprint $table) {
            $table->text('additional_services_json')->nullable()->after('discount_until');
            $table->decimal('additional_services_total', 10, 2)->nullable()->after('additional_services_json');
        });
    }

    public function down(): void
    {
        Schema::connection('contracts')->table('contract_parties', function (Blueprint $table) {
            $table->dropColumn(['additional_services_json', 'additional_services_total']);
        });
    }
};
