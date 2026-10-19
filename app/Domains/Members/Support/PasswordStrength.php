<?php

namespace App\Domains\Members\Support;

/**
 * Síla hesla — stejný výpočet jako Kohana (jquery.validate.password), který
 * se ale vyhodnocoval jen v prohlížeči. Úrovně (Setting security_password_level):
 *   1 velmi slabé — jen opakovaný jeden znak („aaaaaaaa")
 *   2 slabé       — jeden druh znaků
 *   3 dobré       — dva druhy (malá+velká, malá+číslice, velká+číslice)
 *   4 silné       — malá+velká+číslice, písmena + 2 číslice, nebo speciální znak
 * Velké počáteční písmeno se jako velké nepočítá („Heslo1" ≠ silné).
 */
final class PasswordStrength
{
    public const LABELS = [1 => 'velmi slabé', 2 => 'slabé', 3 => 'dobré', 4 => 'silné'];

    public static function rate(string $password): int
    {
        if ($password !== '' && preg_match('/^(.)\1+$/su', $password)) {
            return 1;
        }

        $lower   = (bool) preg_match('/[a-z]/', $password);
        $upper   = (bool) preg_match('/[A-Z]/', lcfirst($password));
        $digit   = (bool) preg_match('/[0-9]/', $password);
        $digits  = (bool) preg_match('/[0-9].*[0-9]/s', $password);
        $special = (bool) preg_match('/[^a-zA-Z0-9]/', $password);

        if (($lower && $upper && $digit) || ($lower && $digits) || ($upper && $digits) || $special) {
            return 4;
        }
        if (($lower && $upper) || ($lower && $digit) || ($upper && $digit)) {
            return 3;
        }
        return 2;
    }

    /** Obsahuje heslo přihlašovací jméno (bez ohledu na velikost písmen)? */
    public static function containsUsername(string $password, ?string $username): bool
    {
        $username = trim((string) $username);
        return $username !== '' && str_contains(mb_strtolower($password), mb_strtolower($username));
    }
}
