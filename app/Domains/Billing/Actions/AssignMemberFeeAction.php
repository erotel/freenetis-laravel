<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Exceptions\MemberFeeRejected;
use App\Domains\Billing\Repositories\MemberFeeRepository;
use App\Models\Fee;
use App\Models\MemberFee;
use App\Services\ContractService;

/**
 * Přiřadí členovi tarif (bez konce = 9999-12-31, priorita 1).
 *
 * Dodatečnou službu (typ „additional service") NELZE přiřadit přímo členovi
 * s podepsanou smlouvou — musí projít dodatkem, který službu přiřadí až po
 * podpisu (apply-on-sign). Jinak by zákazník platil za nepodepsanou službu.
 */
class AssignMemberFeeAction
{
    public function __construct(private MemberFeeRepository $memberFees, private ContractService $contracts)
    {
    }

    /**
     * @param array{fee_id:int, activation_date:string, deactivation_date?:?string, comment?:?string} $data
     * @throws MemberFeeRejected
     */
    public function handle(int $memberId, array $data): MemberFee
    {
        $fee = $this->memberFees->fee((int) $data['fee_id']);
        if ($fee && (int) $fee->type_id === Fee::TYPE_ADDITIONAL_SERVICE) {
            $contract = $this->contracts->getByMemberId($memberId);
            if ($contract && $contract->status === 'signed') {
                throw new MemberFeeRejected('Dodatečnou službu u člena s podepsanou smlouvou přidej dodatkem ke smlouvě (přiřadí se po podpisu), ne přímo v poplatcích.');
            }
        }

        return $this->memberFees->create([
            'member_id'         => $memberId,
            'fee_id'            => $data['fee_id'],
            'activation_date'   => $data['activation_date'],
            'deactivation_date' => $data['deactivation_date'] ?? '9999-12-31',
            'comment'           => $data['comment'] ?? '',
            'priority'          => 1,
        ]);
    }
}
