<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Zpětné ukončení smluv u již ukončených členů/zákazníků (typ 15/16).
 *
 * Bývalí členové, kteří odešli ještě před zavedením stavu „Ukončená", mají
 * smlouvu pořád `signed` → v seznamu /contracts nejsou vidět (seznam bývalé
 * skrývá, pokud smlouva není `terminated`). Tahle migrace jim podepsanou
 * smlouvu překlopí na `terminated`, takže se v přehledu zobrazí a stav odpovídá
 * realitě. Smlouva zůstává jako doklad (PDF, contract_no beze změny).
 *
 * Musí běžet až po přidání hodnoty `terminated` do enumu (migrace 120000).
 * Idempotentní: mění jen `signed`, opakované spuštění nic dalšího neudělá.
 * down() je no-op — nelze spolehlivě rozlišit, které `terminated` vznikly zde.
 */
return new class extends Migration
{
    public function up(): void
    {
        $formerIds = DB::connection('mysql')
            ->table('members')
            ->whereIn('type', [15, 16]) // bývalý člen / bývalý zákazník
            ->pluck('id')
            ->all();

        if (empty($formerIds)) {
            return;
        }

        $total = 0;
        foreach (array_chunk($formerIds, 1000) as $chunk) {
            $contractIds = DB::connection('contracts')
                ->table('contracts')
                ->whereIn('member_id', $chunk)
                ->where('status', 'signed')
                ->pluck('id')
                ->all();

            if (empty($contractIds)) {
                continue;
            }

            DB::connection('contracts')
                ->table('contracts')
                ->whereIn('id', $contractIds)
                ->update(['status' => 'terminated']);

            $now  = now();
            $meta = json_encode(
                ['by' => 'migrace', 'reason' => 'Zpětné ukončení bývalého člena'],
                JSON_UNESCAPED_UNICODE
            );
            $events = array_map(fn ($cid) => [
                'contract_id' => $cid,
                'event'       => 'terminated',
                'meta_json'   => $meta,
                'created_at'  => $now,
            ], $contractIds);

            DB::connection('contracts')->table('contract_events')->insert($events);

            $total += count($contractIds);
        }

        echo "  Zpětně ukončeno smluv bývalých členů: {$total}" . PHP_EOL;
    }

    public function down(): void
    {
        // Nelze spolehlivě vrátit — stav `terminated` mohl vzniknout i jinak.
    }
};
