<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy Kohana držel `users.password_request` na varchar(10). ForgottenPasswordController
 * generuje token přes Str::random(40) → insert padá na 1406 "Data too long" → 500 při
 * žádosti o reset hesla pro existujícího uživatele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_request', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_request', 10)->nullable()->change();
        });
    }
};
