<?php

namespace App\Domains\Members\Support;

use App\Helpers\MemberType;

/**
 * Typ bývalého člena po odchodu: řádný člen (90) → bývalý člen (15), kdokoli
 * jiný (zákazník 2, čekající 18 …) → bývalý zákazník (16). Jediné místo
 * pravidla pro ruční ukončení, Trvale smazat (krok 1) i cron v den odchodu.
 */
final class FormerType
{
    public const TYPES = [MemberType::FORMER, MemberType::FORMER_CUSTOMER];

    public static function for(int $type): int
    {
        return $type === MemberType::REGULAR ? MemberType::FORMER : MemberType::FORMER_CUSTOMER;
    }

    /**
     * Typ po obnovení bývalého (tlačítko Obnovit).
     *
     * POZOR — legacy mapování je NAOPAK: bývalý zákazník (16) → řádný člen (90),
     * bývalý člen (15) → zákazník (2). Zachováno beze změny (charakterizace
     * RestoreMemberTest); na .54 se Obnovit dosud nepoužilo. Oprava = samostatný
     * commit se změnou chování.
     */
    public static function restoredType(int $formerType): int
    {
        return $formerType === MemberType::FORMER_CUSTOMER ? MemberType::REGULAR : MemberType::CUSTOMER;
    }

    public static function isFormer(int $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * Výpovědní lhůta = skutečné datum odchodu v budoucnu (sentinely
     * 0000-00-00 / 9999-12-31 znamenají „neurčeno").
     */
    public static function inNoticePeriod(?string $leavingDate, ?string $today = null): bool
    {
        $leaving = (string) $leavingDate;
        return $leaving !== '' && $leaving !== '0000-00-00' && $leaving !== '9999-12-31'
            && $leaving > ($today ?? now()->format('Y-m-d'));
    }
}
