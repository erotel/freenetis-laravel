<?php

namespace App\Domains\Members\Repositories;

use App\Models\Contract;
use App\Models\ContractEvent;
use Illuminate\Support\Facades\Log;

/**
 * Smlouvy člena při odchodu (contractsdb, connection 'contracts'). Samostatná
 * DB → nejde do transakce nad `mysql`; výpadek contractsdb nesmí shodit už
 * provedené ukončení/smazání člena, proto try/catch + warning.
 */
class MemberContractRepository
{
    /**
     * Podepsané smlouvy → `terminated` („Ukončená") + event. Smlouva zůstává
     * jako právní dokument (PDF). Vrací počet ukončených smluv.
     */
    public function terminateSigned(int $memberId, ?string $leavingDate, ?string $byLogin): int
    {
        try {
            $contracts = Contract::where('member_id', $memberId)
                ->where('status', 'signed')
                ->get();

            foreach ($contracts as $contract) {
                $contract->update(['status' => 'terminated']);
                ContractEvent::create([
                    'contract_id' => $contract->id,
                    'event'       => 'terminated',
                    'meta_json'   => json_encode([
                        'by'     => $byLogin,
                        'reason' => 'Ukončení zákazníka',
                        'date'   => $leavingDate,
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }

            return $contracts->count();
        } catch (\Throwable $e) {
            Log::warning('Nepodařilo se ukončit smlouvy člena #' . $memberId . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Nepodepsané smlouvy (návrh / čeká na podpis / ověřeno / zrušená) smazat —
     * nikdo je už nedokončí. Podepsané i ukončené zůstávají. Child tabulky
     * (contract_parties/events/otps) se domažou přes ON DELETE CASCADE.
     */
    public function purgeUnsigned(int $memberId): void
    {
        try {
            Contract::where('member_id', $memberId)
                ->whereNotIn('status', ['signed', 'terminated'])
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('Nepodařilo se smazat nepodepsané smlouvy člena #' . $memberId . ': ' . $e->getMessage());
        }
    }
}
