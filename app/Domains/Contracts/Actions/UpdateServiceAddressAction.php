<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Support\ContractEventLog;
use App\Models\Contract;
use App\Models\ContractParty;

/**
 * Ruční místo připojení u návrhu smlouvy. PDF vykresluje jen
 * service_full_address — strukturovaná service_* pole se vyprázdní, ať se
 * nemíchá starý snímek s ručním textem.
 */
class UpdateServiceAddressAction
{
    public function handle(Contract $contract, string $address): bool
    {
        if ($contract->status !== 'draft') {
            return false;
        }
        $party = ContractParty::where('contract_id', $contract->id)->orderByDesc('id')->first();
        if (!$party) {
            return false;
        }

        $old = (string) $party->service_full_address;
        $new = trim(mb_substr($address, 0, 255));
        $party->update(['service_full_address' => $new, 'service_street' => null, 'service_town' => null, 'service_zip' => null]);

        ContractEventLog::record($contract->id, 'service_address_edited', [
            'by'   => auth()->user()?->login,
            'from' => $old,
            'to'   => $new,
        ], JSON_UNESCAPED_UNICODE);

        return true;
    }
}
