<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dodatky ke smlouvě jako samostatné řádky (contracts DB) — na rozdíl od legacy
 * „nulový tarif" addonu (Contract.addon_* sloupce), který zůstává beze změny.
 *
 * Každý řádek = jeden dodatek (zatím type='tariff_change'), snímá PŮVODNÍ i NOVÝ
 * tarif+cenu, aby byl neměnný. Podpis (status/pdf_path/signed_at) doplní fáze B.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('contracts')->create('contract_addons', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('contract_id')->index();
            $table->string('type', 32)->default('tariff_change');

            // Snapshot PŮVODNÍHO stavu
            $table->string('old_speed_name', 100)->nullable();
            $table->decimal('old_price', 10, 2)->nullable();
            $table->decimal('old_price_after_discount', 10, 2)->nullable();

            // Snapshot NOVÉHO stavu
            $table->string('new_speed_name', 100)->nullable();
            $table->decimal('new_price', 10, 2)->nullable();
            $table->decimal('new_price_after_discount', 10, 2)->nullable();
            $table->date('discount_until')->nullable();

            $table->date('effective_date');                 // účinnost = 1. den dalšího měsíce
            $table->string('status', 20)->default('draft');  // draft|otp_sent|signed|canceled
            $table->string('pdf_path', 255)->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->string('created_by', 64)->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('contracts')->dropIfExists('contract_addons');
    }
};
