<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'password_request_expires_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dateTime('password_request_expires_at')
                    ->nullable()
                    ->after('password_request');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'password_request_expires_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('password_request_expires_at');
            });
        }
    }
};
