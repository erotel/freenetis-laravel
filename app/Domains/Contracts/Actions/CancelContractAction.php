<?php

namespace App\Domains\Contracts\Actions;

use App\Models\Contract;

/**
 * Zrušení NEpodepsané smlouvy = smazání (návrh nemá právní váhu; uvolní se
 * číslo smlouvy pro novou). Děti (parties/events/otps) padnou kaskádou FK,
 * stopa jde do logu. Podepsané/ukončené sem nespadnou — ty se jen ukončují.
 */
class CancelContractAction
{
    public function handle(Contract $contract, ?string $reason = null): bool
    {
        if (!in_array($contract->status, ['draft', 'otp_sent', 'otp_verified'], true)) {
            return false;
        }

        logger()->info('Zrušen (smazán) návrh smlouvy', [
            'contract_no' => $contract->contract_no,
            'member_id'   => $contract->member_id,
            'status'      => $contract->status,
            'reason'      => $reason,
            'by'          => auth()->user()?->login,
        ]);

        return (bool) $contract->delete();
    }
}
