<?php

namespace App\Rules;

use App\Domains\Members\Support\PasswordStrength;
use App\Models\Setting;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Heslo musí mít aspoň sílu ze Setting security_password_level (výchozí 3)
 * a nesmí obsahovat přihlašovací jméno. Délku hlídá pravidlo min:
 * (security_password_length) zvlášť.
 */
class StrongPassword implements ValidationRule
{
    public function __construct(private ?string $username = null)
    {
    }

    public static function minLength(): int
    {
        return (int) Setting::get('security_password_length', 8);
    }

    /** Sada pravidel pro nové heslo: povinné, délka, síla, potvrzení. */
    public static function rules(?string $username = null, bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'min:' . self::minLength(), new self($username), 'confirmed'];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = (string) $value;
        if (PasswordStrength::containsUsername($password, $this->username)) {
            $fail('Heslo nesmí obsahovat přihlašovací jméno.');
            return;
        }
        $level = max(1, min(4, (int) Setting::get('security_password_level', 3)));
        if (PasswordStrength::rate($password) < $level) {
            $fail('Heslo je příliš slabé (požadováno: ' . PasswordStrength::LABELS[$level] . '). Použijte malá i velká písmena a číslice, případně speciální znak.');
        }
    }
}
