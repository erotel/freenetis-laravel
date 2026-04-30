<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('smtp_exceptions')) {
            return;
        }

        Schema::create('smtp_exceptions', function (Blueprint $table) {
            $table->id();
            $table->text('intip')->charset('latin1')->collation('latin1_swedish_ci');
            $table->string('user', 255)->charset('latin1')->collation('latin1_swedish_ci');
            $table->date('datum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_exceptions');
    }
};
