<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->bigIncrements('id');
            // users.id je signed int(11) → user_id musí být taky signed int (kvůli FK)
            $table->integer('user_id');
            $table->string('credential_id', 255)->unique(); // base64 raw credential ID
            $table->text('public_key');                     // PEM veřejný klíč
            $table->unsignedBigInteger('sign_count')->default(0); // detekce klonu
            $table->string('device_name', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('last_used_at')->nullable();

            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webauthn_credentials');
    }
};
