<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy Kohana držel `invoices.pdf_filename` na varchar(64) — stačilo to pro
 * krátkou cestu typu /usr/share/freenetis/data/invoices/2025/202500001.pdf (53 znaků).
 * Po přesunu do storage/app/private/invoices/ je absolutní cesta delší než 64,
 * takže import nových faktur padá na 1406 "Data too long".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('pdf_filename', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('pdf_filename', 64)->nullable()->change();
        });
    }
};
