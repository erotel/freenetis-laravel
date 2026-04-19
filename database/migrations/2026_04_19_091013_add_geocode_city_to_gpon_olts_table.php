<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('gpon_olts', function (Blueprint $table) {
            $table->string('geocode_city', 100)->nullable()->after('port_count');
        });
    }

    public function down(): void
    {
        Schema::table('gpon_olts', function (Blueprint $table) {
            $table->dropColumn('geocode_city');
        });
    }
};
