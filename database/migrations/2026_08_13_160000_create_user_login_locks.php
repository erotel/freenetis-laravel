<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zámek účtu po N neúspěšných přihlášeních (NIS2/ZoKB).
 *
 * Trvalý (na rozdíl od dosavadního cache rate-limitu) a viditelný pro admina.
 * Časově omezený (locked_until) — po vypršení se sám odemkne. To je záměr:
 * trvalý zámek do ručního odemčení by šel zneužít k DoS (kdokoli zná login,
 * zamkl by cizí účet). Admin může odemknout dřív.
 *
 * failed_count = počet po sobě jdoucích selhání; po dosažení prahu se nastaví
 * locked_until a počítadlo se vynuluje. Úspěšné přihlášení řádek smaže.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('user_login_locks')) {
            return;
        }
        Schema::create('user_login_locks', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_login_locks');
    }
};
