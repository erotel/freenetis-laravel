<?php

namespace App\Domains\Network\Queries;

use App\Domains\Network\Repositories\AllowedSubnetRepository;
use App\Models\Member;

/** Přípojná místa člena; nabídka podsítí a rychlostí jen pro admina. */
class AllowedSubnetsCardQuery
{
    public function __construct(private AllowedSubnetRepository $places)
    {
    }

    public function find(int $memberId, bool $forAdmin): array
    {
        $member = Member::with('speedClass')->findOrFail($memberId);
        $places = $this->places->forMember($memberId);

        return [
            'member'           => $member,
            'allowedSubnets'   => $places,
            'availableSubnets' => $forAdmin ? $this->places->subnetsExcept($places->pluck('subnet_id')) : collect(),
            'maxCount'         => $this->places->limit($memberId),
            'hasOwnLimit'      => $this->places->hasOwnLimit($memberId),
            'globalCount'      => $this->places->globalLimit(),
            'speedClasses'     => $forAdmin ? $this->places->speedClasses() : collect(),
            'memberSpeed'      => $member->speedClass,
        ];
    }
}
