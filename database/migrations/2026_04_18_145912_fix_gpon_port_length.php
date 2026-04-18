<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onts', function (Blueprint $table) {
            $table->string('gpon_port', 64)->change();
        });
    }

    public function down(): void
    {
        Schema::table('onts', function (Blueprint $table) {
            $table->string('gpon_port', 32)->change();
        });
    }
};
