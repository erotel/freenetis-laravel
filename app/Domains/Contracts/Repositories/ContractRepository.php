<?php

namespace App\Domains\Contracts\Repositories;

use App\Helpers\MemberType;
use App\Models\Contract;
use App\Models\ContractEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Smlouvy (contractsdb.contracts). */
class ContractRepository
{
    /** Poslední smlouva člena (jeden člen = jedna smlouva; zrušené návrhy se mažou). */
    public function forMember(int $memberId): ?Contract
    {
        return Contract::where('member_id', $memberId)->orderByDesc('id')->first();
    }

    /**
     * Číslo smlouvy `SML-<rok>-<ID člena na 6 cifer>` (konvence z importu).
     * Obsazené legacy smlouvou jiného člena → suffix -2, -3 … (UNIQUE uq_contract_no).
     */
    public function nextContractNo(int $memberId): string
    {
        $base = sprintf('SML-%s-%06d', date('Y'), $memberId);
        if (!Contract::where('contract_no', $base)->exists()) {
            return $base;
        }
        for ($i = 2; ; $i++) {
            $candidate = $base . '-' . $i;
            if (!Contract::where('contract_no', $candidate)->exists()) {
                return $candidate;
            }
        }
    }

    /** id bývalých členů/zákazníků (15/16) — jejich smlouvy se v seznamu a odznaku skrývají. @return int[] */
    public function formerMemberIds(): array
    {
        return DB::connection('mysql')
            ->table('members')
            ->whereIn('type', [MemberType::FORMER, MemberType::FORMER_CUSTOMER])
            ->pluck('id')
            ->all();
    }

    // ── odchod člena (smlouvy jsou v jiné DB než člen → mimo transakci, best-effort) ──

    /**
     * Podepsané smlouvy → `terminated` („Ukončená") + event. Smlouva zůstává
     * jako právní dokument (PDF). Vrací počet ukončených smluv.
     */
    public function terminateSignedForMember(int $memberId, ?string $leavingDate, ?string $byLogin): int
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
    public function purgeUnsignedForMember(int $memberId): void
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
