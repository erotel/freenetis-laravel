<?php

namespace App\Domains\Members\Repositories;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Přístup k členům v legacy schématu (members, users, contacts).
 * Jediné místo domény Members, které sahá do DB — viz app/Domains/README.md.
 */
class MemberRepository
{
    /** @throws \Illuminate\Database\Eloquent\ModelNotFoundException */
    public function findOrFail(int $id): Member
    {
        return Member::findOrFail($id);
    }

    /** Uloží schválení: nový typ + datum přijetí přihlášky. */
    public function markApproved(Member $member, int $newType, string $acceptedOn): void
    {
        $member->type                   = $newType;
        $member->entrance_form_accepted = $acceptedOn;
        $member->save();
    }

    /**
     * E-mail hlavního uživatele člena (kontakt typu 20). Při více uživatelích /
     * kontaktech vrací první, který DB vydá — shodně s původním chováním.
     */
    public function primaryEmail(int $memberId): ?string
    {
        $userId = DB::table('users')->where('member_id', $memberId)->value('id');
        if (!$userId) {
            return null;
        }

        return DB::table('contacts as c')
            ->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')
            ->where('uc.user_id', $userId)
            ->where('c.type', 20)
            ->value('c.value') ?: null;
    }
}
