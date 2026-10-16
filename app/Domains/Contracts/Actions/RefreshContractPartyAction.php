<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Support\ContractEventLog;
use App\Domains\Contracts\Support\PartySnapshot;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\Member;

/**
 * Přepíše snímek smluvní strany živými daty člena (oprava údajů bez ztráty
 * čísla smlouvy). Jen návrh — u otp_sent/otp_verified už zákazník viděl PDF
 * nebo dostal kód, tam se smlouva ruší a zakládá znovu. Změny do historie.
 */
class RefreshContractPartyAction
{
    public function __construct(private PartySnapshot $parties)
    {
    }

    public function handle(Contract $contract): bool
    {
        if ($contract->status !== 'draft') {
            return false;
        }
        $member = Member::with(['users.contacts.enumType', 'accounts.variableSymbols', 'addressPoint.street', 'addressPoint.town', 'speedClass'])
            ->find($contract->member_id);
        $party = $member ? ContractParty::where('contract_id', $contract->id)->orderByDesc('id')->first() : null;
        if (!$member || !$party) {
            return false;
        }

        $newData = $this->parties->fromMember($member);
        $oldData = $party->only(array_keys($newData));
        $diff = [];
        foreach ($newData as $k => $v) {
            if ((string) ($oldData[$k] ?? '') !== (string) $v) {
                $diff[$k] = ['from' => $oldData[$k] ?? null, 'to' => $v];
            }
        }

        $party->update($newData);
        // telefon je i na smlouvě (OTP)
        if (($contract->phone ?? '') !== $newData['phone']) {
            $contract->update(['phone' => $newData['phone']]);
        }

        ContractEventLog::record($contract->id, 'party_refreshed', ['by' => auth()->user()?->login, 'diff' => $diff], JSON_UNESCAPED_UNICODE);

        return true;
    }
}
