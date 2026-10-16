<?php

namespace App\Domains\Contracts\Support;

use App\Models\Member;
use App\Models\User;

/**
 * Telefon a e-mail uživatele pro smlouvy — první kontakt, jehož typ (enum)
 * obsahuje „tel" / „mail".
 */
final class MemberContacts
{
    /** @return array{0: string, 1: string} [telefon, e-mail]; chybějící = '' */
    public static function of(?User $user): array
    {
        if (!$user) return ['', ''];

        $contacts = $user->contacts()->with('enumType')->get();

        $phone = $contacts
            ->first(fn ($c) => str_contains(strtolower($c->enumType?->value ?? ''), 'tel'))
            ?->value ?? '';

        $email = $contacts
            ->first(fn ($c) => str_contains(strtolower($c->enumType?->value ?? ''), 'mail'))
            ?->value ?? '';

        return [$phone, $email];
    }

    /** Hlavní uživatel člena, nebo null. */
    public static function mainUser(?int $memberId): ?User
    {
        return $memberId ? Member::find($memberId)?->users()->where('type', User::MAIN_USER)->first() : null;
    }
}
