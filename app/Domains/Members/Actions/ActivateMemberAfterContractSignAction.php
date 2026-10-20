<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Events\MemberApproved;
use App\Domains\Members\Repositories\MemberRepository;
use App\Helpers\MemberType;
use App\Domains\Notifications\Actions\SyncPendingCustomerRedirectAction;

/**
 * Synchronizace člena po online podpisu smlouvy: vždy registration=1, žadatel (1)
 * a čekající zákazník (18) → zákazník (2) s datem vstupu = den podpisu (bez něj
 * by ho DeductFees přeskakoval). Čekající člen (17) má vlastní ruční
 * workflow s přihláškou — typ se mu nemění. Aktivace = schválení
 * ({@see MemberApproved}: audit 'approved'; e-mail zákazníkovi listener neposílá).
 */
class ActivateMemberAfterContractSignAction
{
    private const ACTIVATED_BY_SIGN = [MemberType::APPLICANT, MemberType::PENDING_CUSTOMER];

    public function __construct(
        private MemberRepository $members,
        private SyncPendingCustomerRedirectAction $pendingRedirects,
    ) {
    }

    /**
     * @return array{old_type:int,new_type:int}|null  null = člen neexistuje
     */
    public function handle(int $memberId): ?array
    {
        $member = $this->members->find($memberId);
        if (!$member) {
            return null;
        }

        $oldType = $member->type;
        $newType = in_array($oldType, self::ACTIVATED_BY_SIGN, true) ? MemberType::CUSTOMER : $oldType;
        $this->members->markRegistered($member, $newType, now()->toDateString());

        // Po přechodu 18 → 2 okamžitě uvolnit IP z přesměrování (jinak by
        // zákazník čekal až na další běh cronu, max 1 h).
        if ($oldType !== $newType) {
            $this->pendingRedirects->handle($member->id);
            MemberApproved::dispatch($member->id, $oldType, $newType);
        }

        return ['old_type' => $oldType, 'new_type' => $newType];
    }
}
