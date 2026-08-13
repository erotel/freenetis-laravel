<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hygiena přihlašovacích údajů (NIS2/ZoKB): reset-token hesla se nově ukládá
 * jako SHA-256 hash, ne plaintext. Kdo by viděl databázi (záloha, únik), už
 * nemůže aktivní token použít k převzetí účtu.
 *
 * - `password_request` rozšířeno na varchar(64) (sha256 hex).
 * - Existující plaintextové tokeny se zneplatní (jiný formát by stejně
 *   nesedl na nový hash-lookup; mažeme je, ať tam „nevisí").
 */
return new class extends Migration {
    public function up(): void
    {
        // Zneplatnit rozpracované plaintext tokeny (in-flight reset requesty).
        DB::table('users')
            ->whereNotNull('password_request')
            ->update(['password_request' => null, 'password_request_expires_at' => null]);

        // Rozšířit sloupec na délku sha256 hex.
        DB::statement("ALTER TABLE users MODIFY password_request VARCHAR(64) NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY password_request VARCHAR(40) NULL");
    }
};
