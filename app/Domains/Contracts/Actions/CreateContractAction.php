<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Repositories\ContractRepository;
use App\Domains\Contracts\Support\ContractEventLog;
use App\Domains\Contracts\Support\PartySnapshot;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\Member;

/** Návrh smlouvy (draft) se snímkem smluvní strany. Podmínky: ContractPreconditions (volající). */
class CreateContractAction
{
    public function __construct(
        private ContractRepository $contracts,
        private PartySnapshot $parties,
    ) {
    }

    public function handle(Member $member): Contract
    {
        $contractNo = $this->contracts->nextContractNo((int) $member->id);
        $partyData  = $this->parties->fromMember($member);

        $contract = Contract::create([
            'member_id'   => $member->id,
            'contract_no' => $contractNo,
            'status'      => 'draft',
            'phone'       => $partyData['phone'],
        ]);
        ContractParty::create($partyData + ['contract_id' => $contract->id]);

        ContractEventLog::record($contract->id, 'created', [
            'member_id' => $member->id,
            'by'        => 'admin',
            'login'     => auth()->user()?->login,
        ]);

        return $contract;
    }
}
