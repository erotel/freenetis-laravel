<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jednorázový úklid osiřelých návrhů smluv (contractsdb), aby produkce dostala
 * stejný stav jako po ručním úklidu na testu:
 *
 *   - bývalí členové (typ 15/16): nepodepsané návrhy už nikdo nedokončí,
 *   - řádní členové (typ 90): smlouvu vůbec nemají mít.
 *
 * Maže se jen `draft`/`otp_sent`/`otp_verified` — podepsané (`signed`) a ukončené
 * (`terminated`) zůstávají jako doklad. Child tabulky (contract_parties/events/otps)
 * se domažou přes ON DELETE CASCADE. Idempotentní: opakované spuštění nic dalšího
 * nesmaže. down() je záměrně no-op (smazané návrhy nejde obnovit).
 *
 * Napříč dvěma DB: cílové member_id čteme z 'mysql' (members), mažeme na 'contracts'.
 */
return new class extends Migration
{
    public function up(): void
    {
        $targetIds = DB::connection('mysql')
            ->table('members')
            ->whereIn('type', [15, 16, 90]) // bývalý člen, bývalý zákazník, řádný člen
            ->pluck('id')
            ->all();

        if (empty($targetIds)) {
            return;
        }

        $deleted = 0;
        foreach (array_chunk($targetIds, 1000) as $chunk) {
            $deleted += DB::connection('contracts')
                ->table('contracts')
                ->whereIn('member_id', $chunk)
                ->whereIn('status', ['draft', 'otp_sent', 'otp_verified'])
                ->delete();
        }

        echo "  Smazáno osiřelých návrhů smluv: {$deleted}" . PHP_EOL;
    }

    public function down(): void
    {
        // Smazané návrhy nelze obnovit.
    }
};
