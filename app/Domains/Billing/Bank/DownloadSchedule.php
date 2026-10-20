<?php

namespace App\Domains\Billing\Bank;

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
    // Kohana Time_Activity_Rule
    public const TYPE_MONTHLY         = 1;
    public const TYPE_WEEKLY          = 2;
    public const TYPE_DAILY           = 3;
    public const TYPE_DAILY_WD        = 4;
    public const TYPE_HOURLY          = 5;
    public const TYPE_AFTER_DEDUCTION = 6;

    public static function typeLabels(): array
    {
        return [
            self::TYPE_MONTHLY         => 'Měsíčně',
            self::TYPE_WEEKLY          => 'Týdně',
            self::TYPE_DAILY           => 'Denně',
            self::TYPE_DAILY_WD        => 'Denně (pracovní dny)',
            self::TYPE_HOURLY          => 'Každou hodinu',
            self::TYPE_AFTER_DEDUCTION => 'Po srážce poplatků',
        ];
    }

    public static function label(int $type): string
    {
        return self::typeLabels()[$type] ?? 'Neznámý';
    }

    /**
     * Attribute definitions per type (mirrors Time_Activity_Rule::$type_attributes).
     * Each entry: ['name' => label, 'min' => int, 'max' => int]
     */
    public static function typeAttributes(): array
    {
        return [
            self::TYPE_MONTHLY         => [
                ['name' => 'Den v měsíci', 'min' => 1,  'max' => 31],
                ['name' => 'Hodina',       'min' => 0,  'max' => 23],
            ],
            self::TYPE_WEEKLY          => [
                ['name' => 'Den týdne (1=Po, 7=Ne)', 'min' => 1, 'max' => 7],
                ['name' => 'Hodina',                  'min' => 0, 'max' => 23],
            ],
            self::TYPE_DAILY           => [
                ['name' => 'Hodina', 'min' => 0, 'max' => 23],
            ],
            self::TYPE_DAILY_WD        => [
                ['name' => 'Hodina', 'min' => 0, 'max' => 23],
            ],
            self::TYPE_HOURLY          => [],  // no attributes
            self::TYPE_AFTER_DEDUCTION => [
                ['name' => 'Hodina', 'min' => 0, 'max' => 23],
            ],
        ];
    }

    /** Čitelný popis atributů pravidla („Den v měsíci: 26, Hodina: 13“). */
    public static function describe(object $rule): string
    {
        $attrs = $rule->attribute !== null ? explode('/', $rule->attribute) : [];
        $defs  = self::typeAttributes()[$rule->type] ?? [];

        if (empty($defs)) {
            return '—';
        }

        $parts = [];
        foreach ($defs as $i => $def) {
            $val = $attrs[$i] ?? '?';
            $parts[] = $def['name'] . ': ' . $val;
        }
        return implode(', ', $parts);
    }

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
                self::TYPE_MONTHLY => (function () use ($day, $month, $year, $hour, $attr0, $attr1): bool {
                    $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
                    $effectiveDay = max(1, min($daysInMonth, $attr0));
                    return $day === $effectiveDay && $hour === $attr1;
                })(),

                self::TYPE_WEEKLY =>
                    $dayOfWeek === $attr0 && $hour === $attr1,

                self::TYPE_DAILY =>
                    $hour === $attr0,

                self::TYPE_DAILY_WD =>
                    $hour === $attr0 && $dayOfWeek <= 5,

                self::TYPE_HOURLY =>
                    true,

                self::TYPE_AFTER_DEDUCTION => (function () use ($hour, $day, $month, $year, $attr0): bool {
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
