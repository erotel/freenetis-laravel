<?php

namespace App\Domains\Notifications\Activation;

/**
 * Pravidlo automatické aktivace zprávy (messages_automatical_activations,
 * Kohana Time_Activity_Rule): kdy pravidlo platí.
 *   1 měsíčně „DD/H"   2 týdně „D/H" (1 = Po … 7 = Ne)   3 denně „/H"
 *   4 pracovní dny „/H"   5 každou hodinu   6 v den srážky „/H"
 */
final class ActivationRule
{
    public static function isActive(object $rule, int $dayOfMonth, int $hour, int $weekday, int $deductDay): bool
    {
        $attribute = trim($rule->attribute ?? '');

        switch ((int) $rule->type) {
            case 1:
                [$day, $h] = array_pad(explode('/', $attribute), 2, 0);
                return (int) $day === $dayOfMonth && (int) $h === $hour;
            case 2:
                [$day, $h] = array_pad(explode('/', $attribute), 2, 0);
                return (int) $day === $weekday && (int) $h === $hour;
            case 3:
                return (int) ltrim($attribute, '/') === $hour;
            case 4:
                return $weekday <= 5 && (int) ltrim($attribute, '/') === $hour;
            case 5:
                return true;
            case 6:
                return $dayOfMonth === $deductDay && (int) ltrim($attribute, '/') === $hour;
            default:
                return false;
        }
    }

    /**
     * Kontrola atributu podle typu — neplatný se dřív potichu vyhodnotil jako
     * hodina 0 (např. měsíční „25" bez hodiny = upozornění o půlnoci).
     * @return ?string chybová hláška, null = v pořádku
     */
    public static function attributeError(int $type, ?string $attribute): ?string
    {
        $a = trim((string) $attribute);
        $ok = match ($type) {
            1 => preg_match('~^(\d{1,2})/(\d{1,2})$~', $a, $m) && $m[1] >= 1 && $m[1] <= 31 && $m[2] <= 23,
            2 => preg_match('~^([1-7])/(\d{1,2})$~', $a, $m) && $m[2] <= 23,
            3, 4, 6 => preg_match('~^/?(\d{1,2})$~', $a, $m) && $m[1] <= 23,
            default => true,
        };
        return $ok ? null : match ($type) {
            1 => 'Měsíční pravidlo potřebuje den a hodinu ve tvaru „DD/H" (den 1–31, hodina 0–23), např. 25/9.',
            2 => 'Týdenní pravidlo potřebuje den a hodinu ve tvaru „D/H" (1 = Po … 7 = Ne, hodina 0–23), např. 1/9.',
            default => 'Pravidlo potřebuje hodinu ve tvaru „/H" (0–23), např. /9.',
        };
    }
}
