<?php

namespace App\Domains\Members\Repositories;

use App\Models\Contact;
use App\Models\EnumType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kontakty uživatelů (contacts + pivot users_contacts). Legacy data sdílí
 * jeden řádek kontaktu mezi víc uživateli (rodinný e-mail) — změna se proto
 * u sdíleného řádku „forkne" na vlastní řádek, smazání jen odpojí.
 */
class ContactRepository
{
    public function user(int $id): ?User
    {
        return User::find($id);
    }

    public function forUser(User $user): Collection
    {
        return $user->contacts()->with('enumType')->get();
    }

    /** Typy pro nový kontakt (bez zastaralých protokolů ICQ/MSN/Jabber/Skype). */
    public function newContactTypes(): Collection
    {
        return EnumType::where('type_id', EnumType::CONTACT_GROUP_ID)->notDeprecated()->orderBy('id')->pluck('value', 'id');
    }

    public function linked(int $userId, int $type, string $value): bool
    {
        return Contact::where('type', $type)->where('value', $value)->whereHas('users', fn ($q) => $q->where('users.id', $userId))->exists();
    }

    /** Kontakt uživatele (jen napojený), volitelně s typem. */
    public function ofUser(int $userId, int $contactId, bool $withType = false): ?Contact
    {
        return Contact::when($withType, fn ($q) => $q->with('enumType'))
            ->whereHas('users', fn ($q) => $q->where('users.id', $userId))->find($contactId);
    }

    /** Napojí uživatele na (existující nebo nový) řádek kontaktu. */
    public function attach(int $userId, int $type, string $value): void
    {
        DB::transaction(function () use ($userId, $type, $value) {
            $contact = Contact::firstOrCreate(['type' => $type, 'value' => $value]);
            if (!$contact->users()->where('users.id', $userId)->exists()) {
                $contact->users()->attach($userId, ['mail_redirection' => 0]);
            }
        });
    }

    /** Nová hodnota: sdílený řádek se „forkne", vlastní se změní na místě. */
    public function change(Contact $contact, int $userId, string $value): void
    {
        if ($contact->users()->count() > 1) {
            DB::transaction(function () use ($contact, $userId, $value) {
                $contact->users()->detach($userId);
                $this->attach($userId, (int) $contact->type, $value);
            });
            return;
        }
        $contact->value = $value;
        $contact->save();
    }

    public function countOfType(User $user, int $type): int
    {
        return $user->contacts()->where('type', $type)->count();
    }

    /** Odpojí kontakt od uživatele; řádek bez dalších uživatelů smaže. */
    public function detach(Contact $contact, int $userId): void
    {
        DB::transaction(function () use ($contact, $userId) {
            $contact->users()->detach($userId);
            if ($contact->users()->count() === 0) {
                $contact->delete();
            }
        });
    }
}
