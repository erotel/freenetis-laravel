<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Support\ContractEventLog;
use App\Models\Contract;

/** Ukončení podepsané smlouvy (výpověď) — zůstává jako právní dokument, stav terminated. */
class TerminateContractAction
{
    public function handle(Contract $contract, ?string $reason = null, ?string $terminationDate = null): bool
    {
        if ($contract->status !== 'signed') {
            return false;
        }
        $contract->update(['status' => 'terminated']);

        ContractEventLog::record($contract->id, 'terminated', [
            'by'     => auth()->user()?->login,
            'reason' => $reason,
            'date'   => $terminationDate,
        ], JSON_UNESCAPED_UNICODE);

        return true;
    }
}
