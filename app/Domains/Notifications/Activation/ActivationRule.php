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
}
