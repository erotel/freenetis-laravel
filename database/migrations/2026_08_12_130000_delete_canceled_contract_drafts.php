<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jednorázový úklid zrušených návrhů smluv (contractsdb).
 *
 * Dřív se nepodepsaný návrh při zrušení jen označil statusem `canceled` a řádek
 * zůstal v DB — u některých členů se tak nahromadilo víc mrtvých návrhů a
 * „spotřebovala" se čísla smluv. Nově se návrh při zrušení maže
 * (ContractService::cancelContract), takže tenhle historický balast smažeme.
 *
 * Maže se JEN status `canceled`. Podepsané (`signed`) a ukončené (`terminated`)
 * smlouvy zůstávají jako doklad; rozpracované `draft`/`otp_sent` se nechávají
 * (mohou být rozdělané). Child tabulky padnou přes ON DELETE CASCADE.
 * down() je no-op — smazané návrhy nelze obnovit.
 */
return new class extends Migration {
    public function up(): void
    {
        $deleted = DB::connection('contracts')
            ->table('contracts')
            ->where('status', 'canceled')
            ->delete();

        echo "  Smazáno zrušených návrhů smluv (canceled): {$deleted}" . PHP_EOL;
    }

    public function down(): void
    {
        // Smazané návrhy nelze obnovit.
    }
};
