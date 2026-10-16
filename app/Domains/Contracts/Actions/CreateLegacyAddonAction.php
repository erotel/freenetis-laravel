<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Support\ContractEventLog;
use App\Models\Contract;

/** Starý dodatek (příznak contracts.addon — nulový tarif z importu) k podepsané smlouvě. */
class CreateLegacyAddonAction
{
    public function handle(int $contractId): bool
    {
        $updated = Contract::where('id', $contractId)->where('status', 'signed')->where('addon', 0)->update(['addon' => 1]);
        if ($updated) {
            ContractEventLog::record($contractId, 'addon_created', ['by' => auth()->user()?->login]);
        }
        return $updated > 0;
    }

    /** Stav starého dodatku: 'none' | 'pending' | 'signed'. */
    public static function status(Contract $contract): string
    {
        if (!$contract->addon) return 'none';
        return $contract->addon_signed ? 'signed' : 'pending';
    }
}
