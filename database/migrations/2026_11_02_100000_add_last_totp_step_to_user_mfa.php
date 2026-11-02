<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TOTP kód šel při přihlášení použít opakovaně až do konce tolerančního okna
 * (±30 s → až 90 s). `last_totp_step` = 30s krok posledního přijatého kódu;
 * stejný nebo starší krok se znovu nepřijme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_mfa', function (Blueprint $table) {
            $table->unsignedBigInteger('last_totp_step')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_mfa', function (Blueprint $table) {
            $table->dropColumn('last_totp_step');
        });
    }
};
