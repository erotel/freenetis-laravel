<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Exceptions\MemberNotRestorable;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Support\FormerType;
use App\Services\AuditLogger;
use App\Services\MemberFeesTermination;

/**
 * Obnovení bývalého člena (15/16): typ dle {@see FormerType::restoredType()},
 * odemknout, bez data odchodu; poplatky, které automatika ukončila k datu
 * odchodu, zase oživit. VS (+U) ani rychlost se nemění. Oprávnění kontroluje volající.
 */
class RestoreFormerMemberAction
{
    public function __construct(private MemberRepository $members)
    {
    }

    /**
     * @return int nový typ
     * @throws MemberNotRestorable
     */
    public function handle(object $member): int
    {
        $id = (int) $member->id;
        if (!FormerType::isFormer((int) $member->type)) {
            throw MemberNotRestorable::notFormer();
        }

        $newType    = FormerType::restoredType((int) $member->type);
        $oldLeaving = $member->leaving_date;

        $this->members->markRestored($id, $newType);
        AuditLogger::log('updated', 'members', $id, [
            'type'         => $member->type,
            'locked'       => $member->locked,
            'leaving_date' => $oldLeaving,
        ], [
            'type'         => $newType,
            'locked'       => 0,
            'leaving_date' => '9999-12-31',
            'note'         => 'obnovení bývalého člena',
        ]);
        MemberFeesTermination::reactivate($id, $oldLeaving);

        return $newType;
    }
}
