<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Exceptions\MemberFeeRejected;
use App\Domains\Billing\Repositories\MemberFeeRepository;
use App\Models\Fee;
use App\Models\MemberFee;
use App\Domains\Contracts\Repositories\ContractRepository;

/**
 * Přiřadí členovi tarif (bez konce = 9999-12-31, priorita 1).
 *
 * Dodatečnou službu (typ „additional service") NELZE přiřadit přímo členovi
 * s podepsanou smlouvou — musí projít dodatkem, který službu přiřadí až po
 * podpisu (apply-on-sign). Jinak by zákazník platil za nepodepsanou službu.
 */
class AssignMemberFeeAction
{
    public function __construct(private MemberFeeRepository $memberFees, private ContractRepository $contracts)
    {
    }

    /**
     * @param array{fee_id:int, activation_date:string, deactivation_date?:?string, comment?:?string} $data
     * @throws MemberFeeRejected
     */
    public function handle(int $memberId, array $data): MemberFee
    {
        $this->guardAdditionalService($memberId, (int) $data['fee_id']);

        return $this->memberFees->create([
            'member_id'         => $memberId,
            'fee_id'            => $data['fee_id'],
            'activation_date'   => $data['activation_date'],
            'deactivation_date' => $data['deactivation_date'] ?? '9999-12-31',
            'comment'           => $data['comment'] ?? '',
            'priority'          => 1,
        ]);
    }

    /**
     * Úprava přiřazení — změna na dodatečnou službu podléhá stejné kontrole
     * smlouvy jako nové přiřazení (jinak by šla obejít přes úpravu).
     *
     * @param array{fee_id:int, activation_date:string, deactivation_date?:?string, comment?:?string} $data
     * @throws MemberFeeRejected
     */
    public function update(MemberFee $memberFee, array $data): void
    {
        if ((int) $data['fee_id'] !== (int) $memberFee->fee_id) {
            $this->guardAdditionalService((int) $memberFee->member_id, (int) $data['fee_id']);
        }

        $this->memberFees->update($memberFee, [
            'fee_id'            => $data['fee_id'],
            'activation_date'   => $data['activation_date'],
            'deactivation_date' => $data['deactivation_date'] ?? '9999-12-31',
            'comment'           => $data['comment'] ?? '',
        ]);
    }

    /** @throws MemberFeeRejected */
    private function guardAdditionalService(int $memberId, int $feeId): void
    {
        $fee = $this->memberFees->fee($feeId);
        if ($fee && (int) $fee->type_id === Fee::TYPE_ADDITIONAL_SERVICE) {
            $contract = $this->contracts->forMember($memberId);
            if ($contract && $contract->status === 'signed') {
                throw new MemberFeeRejected('Dodatečnou službu u člena s podepsanou smlouvou přidej dodatkem ke smlouvě (přiřadí se po podpisu), ne přímo v poplatcích.');
            }
        }
    }
}
