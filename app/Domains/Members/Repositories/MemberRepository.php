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

    /**
     * Podepsaná přihláška/smlouva (registration=1), případně se změnou typu.
     * Změna typu = schválení → datum vstupu i přijetí přihlášky = $approvedOn.
     */
    public function markRegistered(Member $member, int $type, string $approvedOn): void
    {
        $updates = ['registration' => 1];
        if ($type !== (int) $member->type) {
            $updates['type']          = $type;
            $updates['entrance_date'] = $approvedOn;
            $member->entrance_form_accepted = $approvedOn;   // není fillable
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

    /** Surový řádek members (hodnoty bez castů modelu — pro audit původních hodnot). */
    public function row(int $id): ?object
    {
        return DB::table('members')->where('id', $id)->first();
    }

    /**
     * Zapíše ukončení: typ, zámek, datum odchodu, rychlost a reset prepaid flagů
     * (bývalý / odcházející člen už není kandidát na ukončení ani na redirect
     * pro nedostatek kreditu).
     */
    public function markLeaving(int $id, int $type, int $locked, string $leavingDate, ?int $speedClassId): void
    {
        DB::table('members')->where('id', $id)->update([
            'type'                  => $type,
            'locked'                => $locked,
            'leaving_date'          => $leavingDate,
            'payment_blocked'       => 0,
            'payment_blocked_since' => null,
            'pending_termination'   => 0,
            'speed_class_id'        => $speedClassId,
        ]);
    }

    /** Bývalý k datu: typ 15/16, zamčený, rychlost „žádná" (prepaid flagy beze změny). */
    public function markFormer(int $id, int $formerType, string $leavingDate): void
    {
        DB::table('members')->where('id', $id)->update([
            'type'           => $formerType,
            'locked'         => 1,
            'leaving_date'   => $leavingDate,
            'speed_class_id' => null,
        ]);
    }

    /**
     * „+U" k (prvnímu) variabilnímu symbolu kreditního účtu — po odchodu se VS
     * už nepáruje s platbami (jako Kohana). Idempotentní.
     */
    public function markVariableSymbolLeft(int $memberId): void
    {
        $accountId = DB::table('accounts')
            ->where('member_id', $memberId)
            ->where('account_attribute_id', 221100)
            ->value('id');
        if (!$accountId) {
            return;
        }
        $vs = DB::table('variable_symbols')->where('account_id', $accountId)->orderBy('id')->first();
        if ($vs && !str_ends_with($vs->variable_symbol, '+U')) {
            DB::table('variable_symbols')->where('id', $vs->id)->update(['variable_symbol' => $vs->variable_symbol . '+U']);
        }
    }

    /**
     * Trvale smaže člena a jeho data: kontakty uživatelů, uživatele, účty
     * s převody (obě strany) a VS, poplatky, povolené podsítě, člena. Zařízení
     * maže volající (MemberDeviceRepository::deleteDevices) předem.
     *
     * Přímo přiřazené IP (ip_addresses.member_id) se nemažou — FK SET NULL je
     * nechá bez vlastníka (zachované legacy chování). subnets_owners a další
     * vazby se smažou kaskádou FK. Volat uvnitř transakce.
     */
    public function purge(int $id): void
    {
        $userIds    = DB::table('users')->where('member_id', $id)->pluck('id');
        $contactIds = DB::table('users_contacts')->whereIn('user_id', $userIds)->pluck('contact_id');
        DB::table('users_contacts')->whereIn('user_id', $userIds)->delete();
        DB::table('contacts')->whereIn('id', $contactIds)->delete();

        DB::table('users')->where('member_id', $id)->delete();

        $accountIds = DB::table('accounts')->where('member_id', $id)->pluck('id');
        DB::table('variable_symbols')->whereIn('account_id', $accountIds)->delete();
        DB::table('transfers')->whereIn('origin_id', $accountIds)->delete();
        DB::table('transfers')->whereIn('destination_id', $accountIds)->delete();
        DB::table('accounts')->where('member_id', $id)->delete();

        DB::table('members_fees')->where('member_id', $id)->delete();
        DB::table('allowed_subnets')->where('member_id', $id)->delete();
        DB::table('members')->where('id', $id)->delete();
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
