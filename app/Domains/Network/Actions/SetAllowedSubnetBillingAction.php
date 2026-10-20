<?php

namespace App\Domains\Network\Actions;

use App\Domains\Network\Exceptions\PlaceBillingRejected;
use App\Domains\Network\Repositories\AllowedSubnetRepository;
use App\Models\AllowedSubnet;
use App\Domains\Contracts\Repositories\ContractRepository;

/**
 * Účtování přípojného místa (cena podle jeho rychlosti). Zapnout jde jen
 * u místa s vlastní rychlostí a ne u člena s podepsanou smlouvou — tam
 * zpoplatní místo až podepsaný dodatek (apply-on-sign). Vypnutí vždy.
 */
class SetAllowedSubnetBillingAction
{
    public function __construct(private AllowedSubnetRepository $places, private ContractRepository $contracts)
    {
    }

    /** @throws PlaceBillingRejected */
    public function handle(AllowedSubnet $as, bool $charged): void
    {
        if ($charged && !$as->speed_class_id) {
            throw new PlaceBillingRejected('Účtovat lze jen místo s vlastní rychlostí. Nejprve mu nastavte rychlost (ne „zdědit").');
        }
        if ($charged) {
            $contract = $this->contracts->forMember((int) $as->member_id);
            if ($contract && $contract->status === 'signed') {
                throw new PlaceBillingRejected('Zpoplatnění přípojného místa u člena s podepsanou smlouvou zapni dodatkem ke smlouvě (začne účtovat po podpisu), ne přímo v podsítích.', true);
            }
        }
        $this->places->update($as, ['charged' => $charged]);
    }
}
