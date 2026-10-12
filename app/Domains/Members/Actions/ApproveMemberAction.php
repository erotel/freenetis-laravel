<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Events\MemberApproved;
use App\Domains\Members\Exceptions\MemberNotApprovable;
use App\Domains\Members\Repositories\MemberRepository;
use App\Helpers\MemberType;
use App\Models\Member;

/**
 * Schválení čekatele: čekající člen (17) → řádný (90), čekající zákazník (18)
 * → zákazník (2). Vedlejší efekty (uvítací e-mail, …) řeší listenery
 * {@see MemberApproved}. Oprávnění kontroluje volající.
 */
class ApproveMemberAction
{
    private const TRANSITIONS = [
        MemberType::PENDING_MEMBER   => MemberType::REGULAR,
        MemberType::PENDING_CUSTOMER => MemberType::CUSTOMER,
    ];

    public function __construct(private MemberRepository $members)
    {
    }

    /**
     * @throws MemberNotApprovable
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function handle(int $memberId): Member
    {
        $member       = $this->members->findOrFail($memberId);
        $previousType = (int) $member->type;

        if (!isset(self::TRANSITIONS[$previousType])) {
            throw MemberNotApprovable::notPending();
        }
        if (!$member->registration) {
            throw MemberNotApprovable::registrationNotSigned();
        }

        $newType = self::TRANSITIONS[$previousType];
        $this->members->markApproved($member, $newType, now()->toDateString());

        MemberApproved::dispatch($member->id, $previousType, $newType);

        return $member;
    }
}
