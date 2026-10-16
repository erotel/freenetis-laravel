<?php

namespace App\Domains\Contracts\Support;

use App\Helpers\MemberType;
use App\Models\Contract;
use App\Models\Member;
use App\Models\User;

/** Proč členovi nelze založit smlouvu (pořadí kontrol = pořadí hlášek ve správě). */
final class ContractPreconditions
{
    public const REGULAR_MEMBER = 'regular_member';  // řádný člen (90) smlouvu nemá
    public const EXISTS         = 'exists';          // už má smlouvu (mimo zrušenou)
    public const NO_SPEED       = 'no_speed';        // bez třídy rychlosti
    public const NO_BIRTHDAY    = 'no_birthday';     // fyzická osoba bez data narození (a bez IČO)

    /** @return ?string kód důvodu, null = lze založit */
    public static function whyNotCreatable(Member $member, ?Contract $existing): ?string
    {
        if ((int) $member->type === MemberType::REGULAR) {
            return self::REGULAR_MEMBER;
        }
        if ($existing && !in_array($existing->status, ['canceled'])) {
            return self::EXISTS;
        }
        if (!$member->speed_class_id) {
            return self::NO_SPEED;
        }
        // Bez data narození (a bez IČO) je v PDF buňka „Datum narození (IČO, DIČ)" prázdná.
        $mainUser    = $member->users->firstWhere('type', User::MAIN_USER);
        $hasIco      = trim((string) $member->organization_identifier) !== '';
        $birthday    = $mainUser?->birthday;
        $hasBirthday = $birthday && (string) $birthday !== '0000-00-00';
        if (!$hasIco && !$hasBirthday) {
            return self::NO_BIRTHDAY;
        }
        return null;
    }
}
