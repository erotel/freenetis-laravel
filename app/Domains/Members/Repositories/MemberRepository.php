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

    public function find(int $id): ?Member
    {
        return Member::find($id);
    }

    /** Podepsaná přihláška/smlouva (registration=1), případně se změnou typu. */
    public function markRegistered(Member $member, int $type): void
    {
        $updates = ['registration' => 1];
        if ($type !== (int) $member->type) {
            $updates['type'] = $type;
        }
        $member->update($updates);
    }

    /** Uloží schválení: nový typ, datum přijetí přihlášky a datum vstupu (= den schválení). */
    public function markApproved(Member $member, int $newType, string $approvedOn): void
    {
        $member->type                   = $newType;
        $member->entrance_form_accepted = $approvedOn;
        $member->entrance_date          = $approvedOn;
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
