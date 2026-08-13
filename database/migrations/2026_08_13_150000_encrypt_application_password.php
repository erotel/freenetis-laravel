<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Hygiena přihlašovacích údajů (NIS2/ZoKB): users.application_password se
 * ukládá šifrovaně at-rest (Laravel Crypt / APP_KEY), ne v plaintextu.
 * V aplikaci se zatím nikde neověřuje — jen zobrazuje adminovi, takže
 * šifrování (na rozdíl od hashe) zachová čitelnost přes model accessor.
 *
 * - Sloupec rozšířen na TEXT (šifrovaný blob je delší než 50 znaků).
 * - Existující plaintextové hodnoty se jednorázově zašifrují (idempotentně:
 *   co už jde dešifrovat, se přeskočí).
 */
return new class extends Migration {
    public function up(): void
    {
        // ALTER na TEXT přestaví tabulku a strict sql_mode by spadl na legacy
        // hodnotách birthday='0000-00-00'. Dočasně uvolníme sql_mode.
        $original = DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        DB::statement("SET SESSION sql_mode = ''");
        try {
            DB::statement("ALTER TABLE users MODIFY application_password TEXT NOT NULL");
        } finally {
            DB::statement('SET SESSION sql_mode = ' . DB::getPdo()->quote($original));
        }

        DB::table('users')
            ->select('id', 'application_password')
            ->whereNotNull('application_password')
            ->where('application_password', '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $r) {
                    // Už zašifrované? Necháme být (idempotence).
                    try {
                        Crypt::decryptString($r->application_password);
                        continue;
                    } catch (\Throwable) {
                        // plaintext → zašifrovat
                    }
                    DB::table('users')->where('id', $r->id)->update([
                        'application_password' => Crypt::encryptString($r->application_password),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Dešifrovat zpět do plaintextu a zúžit sloupec.
        DB::table('users')
            ->select('id', 'application_password')
            ->whereNotNull('application_password')
            ->where('application_password', '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $r) {
                    try {
                        $plain = Crypt::decryptString($r->application_password);
                    } catch (\Throwable) {
                        continue; // už plaintext
                    }
                    DB::table('users')->where('id', $r->id)->update([
                        'application_password' => mb_substr($plain, 0, 50),
                    ]);
                }
            });

        $original = DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        DB::statement("SET SESSION sql_mode = ''");
        try {
            DB::statement("ALTER TABLE users MODIFY application_password VARCHAR(50) NOT NULL");
        } finally {
            DB::statement('SET SESSION sql_mode = ' . DB::getPdo()->quote($original));
        }
    }
};
