<?php

namespace App\Domains\Billing\Bank;

use App\Models\BankAccountAutoDownload as Rule;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Pravidla automatického stahování výpisů (bank_accounts_automatical_downloads)
 * — Kohana Time_Activity_Rule::filter_rules() s AM_BANK_STATEMENTS='55'
 * (plánovač běží každou hodinu v :55). Atribut „den/hodina“ podle typu:
 *   1 měsíčně (den/hodina; den mimo měsíc → nejbližší platný), 2 týdně
 *   (1 = Po … 7 = Ne / hodina), 3 denně (hodina), 4 pracovní dny (hodina),
 *   5 každou hodinu, 6 po srážce (hodina v deduct_day, jen se zapnutou
 *   automatickou srážkou).
 */
class DownloadSchedule
{
    /** Splňuje okamžik $time aspoň jedno pravidlo? */
    public function matches(Collection $rules, int $time): bool
    {
        $hour      = (int) date('G', $time);   // 0-23
        $day       = (int) date('j', $time);   // 1-31
        $month     = (int) date('n', $time);   // 1-12
        $year      = (int) date('Y', $time);
        $dayOfWeek = (int) date('N', $time);   // 1=Mon … 7=Sun

        foreach ($rules as $rule) {
            $attrs = $rule->attribute !== null ? explode('/', $rule->attribute) : [];
            $attr0 = isset($attrs[0]) ? (int) $attrs[0] : 0; // primary attribute
            $attr1 = isset($attrs[1]) ? (int) $attrs[1] : 0; // secondary attribute (hour)

            $passed = match ((int) $rule->type) {
                Rule::TYPE_MONTHLY => (function () use ($day, $month, $year, $hour, $attr0, $attr1): bool {
                    $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
                    $effectiveDay = max(1, min($daysInMonth, $attr0));
                    return $day === $effectiveDay && $hour === $attr1;
                })(),

                Rule::TYPE_WEEKLY =>
                    $dayOfWeek === $attr0 && $hour === $attr1,

                Rule::TYPE_DAILY =>
                    $hour === $attr0,

                Rule::TYPE_DAILY_WD =>
                    $hour === $attr0 && $dayOfWeek <= 5,

                Rule::TYPE_HOURLY =>
                    true,

                Rule::TYPE_AFTER_DEDUCTION => (function () use ($hour, $day, $month, $year, $attr0): bool {
                    if (!Setting::get('deduct_fees_automatically_enabled', 0)) return false;
                    $deductDay  = (int) Setting::get('deduct_day', 26);
                    $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
                    $effectiveDay = min($deductDay, $daysInMonth);
                    return $hour === $attr0 && $day === $effectiveDay;
                })(),

                default => false,
            };

            if ($passed) {
                return true;
            }
        }

        return false;
    }
}
