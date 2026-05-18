<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy Kohana držel `login_logs.IP_address` na varchar(15) — stačilo to pro
 * IPv4 ("255.255.255.255" = 15 znaků). Když se uživatel přihlásí přes IPv6
 * (např. `2a07:9c0:17:1702:afd9:df4d:41d2:fbea` = 39 znaků), insert padá na
 * 1406 "Data too long" → 500. Po F5 už je uživatel přihlášený (Auth::attempt
 * proběhl před failnutým insertem), takže RedirectIfAuthenticated ho pošle
 * na dashboard a vypadá to, že "to po F5 jede".
 *
 * 45 = max délka IPv4-mapped IPv6 ("::ffff:255.255.255.255").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_logs', function (Blueprint $table) {
            $table->string('IP_address', 45)->change();
        });
    }

    public function down(): void
    {
        Schema::table('login_logs', function (Blueprint $table) {
            $table->string('IP_address', 15)->change();
        });
    }
};
