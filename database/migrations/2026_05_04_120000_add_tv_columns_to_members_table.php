<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->tinyInteger('tv_active')->default(0)->after('comment');
            $table->date('tv_valid_until')->nullable()->after('tv_active');
            $table->dateTime('tv_synced_at')->nullable()->after('tv_valid_until');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['tv_active', 'tv_valid_until', 'tv_synced_at']);
        });
    }
};
