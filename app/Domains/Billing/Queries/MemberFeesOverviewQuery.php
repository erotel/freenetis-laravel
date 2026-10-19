<?php

namespace App\Domains\Billing\Queries;

use App\Domains\Billing\Repositories\MemberFeeRepository;
use App\Models\Member;
use App\Models\MemberFee;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Přehled tarifů člena podle typu poplatku + výchozí tarif typu člena
 * z nastavení (default_fee_member_type_{typ}) jako virtuální neuložené
 * přiřazení — platí, když člen vlastní tarif daného typu nemá.
 */
class MemberFeesOverviewQuery
{
    public function __construct(private MemberFeeRepository $memberFees)
    {
    }

    /** @return array{memberFees: Collection, defaultFees: Collection} */
    public function handle(Member $member): array
    {
        return [
            'memberFees'  => $this->memberFees->forMember($member->id)->groupBy(fn ($mf) => $mf->fee->type_id),
            'defaultFees' => $this->defaultFees($member),
        ];
    }

    private function defaultFees(Member $member): Collection
    {
        $defaultFeeId = (int) Setting::get('default_fee_member_type_' . $member->type, 0);
        $defaultFee = $defaultFeeId ? $this->memberFees->fee($defaultFeeId) : null;
        if (!$defaultFee) {
            return collect();
        }

        $virtual = new MemberFee([
            'fee_id'            => $defaultFee->id,
            'member_id'         => 1,
            'activation_date'   => $defaultFee->from,
            'deactivation_date' => $defaultFee->to,
            'priority'          => 0,
            'comment'           => '',
        ]);
        $virtual->setRelation('fee', $defaultFee);

        return collect([$virtual])->groupBy(fn ($mf) => $mf->fee->type_id);
    }
}
