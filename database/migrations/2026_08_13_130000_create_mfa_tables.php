<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFA (dvoufázové přihlášení, NIS2/ZoKB) — fáze A (self-service TOTP).
 *
 * user_mfa: jeden TOTP secret na uživatele (šifrovaný at-rest přes model cast).
 *   confirmed_at = kdy uživatel dokončil setup (do té doby MFA neplatí).
 * mfa_recovery_codes: jednorázové záložní kódy (jen hash), pro přístup při
 *   ztrátě telefonu.
 *
 * user_id bez FK constraintu (stejně jako webauthn_credentials) — legacy users
 * tabulka není spravovaná migracemi.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('user_mfa')) {
            Schema::create('user_mfa', function (Blueprint $table) {
                $table->id();
                $table->integer('user_id');
                $table->text('totp_secret');            // šifrováno v modelu (cast 'encrypted')
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
                $table->unique('user_id');
            });
        }

        if (!Schema::hasTable('mfa_recovery_codes')) {
            Schema::create('mfa_recovery_codes', function (Blueprint $table) {
                $table->id();
                $table->integer('user_id');
                $table->string('code_hash');
                $table->timestamp('used_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('user_mfa');
    }
};
