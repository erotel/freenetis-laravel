<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dvě nezávislé úpravy ve stejné migraci:
 *
 *  1. enum_types.id=21 — přejmenování z "Phone" na "Telefon".
 *     V dev DB už ručně přepsáno (viz CHANGELOG 2.1.1), na produkci ne.
 *     Idempotentní: WHERE id=21 AND value='Phone'.
 *
 *  2. contacts.type=5 → 21. RegistrationController ukládal telefonní
 *     kontakty self-registrace s contacts.type=5 (což je enum id=5 =
 *     "Nečlen", member type — nesmysl pro contact). V UI se pak telefon
 *     u nově registrovaných zobrazoval s labelem "Nečlen" místo "Telefon".
 *     Kód opraven, tato migrace dorovná historické řádky.
 *
 *     contacts.type=5 nemá žádný legitimní význam (id=5 v enum_types je
 *     member type, ne contact type), takže bezpečné updatovat všechny.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('enum_types')
            ->where('id', 21)
            ->where('value', 'Phone')
            ->update(['value' => 'Telefon']);

        DB::table('contacts')
            ->where('type', 5)
            ->update(['type' => 21]);
    }

    public function down(): void
    {
        // Reverze rename necháváme — produkce dříve měla "Phone", ale na
        // dev už je "Telefon" odjakživa, takže rollback by nebyl symetrický
        // mezi prostředími. contacts.type rollback ani nemá smysl
        // (type=5 byl bug, ne validní stav).
    }
};
