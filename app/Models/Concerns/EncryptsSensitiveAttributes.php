<?php

namespace App\Models\Concerns;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Pomocné metody pro symetrické šifrování citlivých atributů (Laravel Crypt,
 * APP_KEY). Čtení má fallback na legacy plaintext (try/catch), takže existující
 * nezašifrované řádky se přečtou tak jak jsou a při dalším uložení se zašifrují.
 * Stejný princip jako App\Models\Setting.
 */
trait EncryptsSensitiveAttributes
{
    protected static function encryptSensitive(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        return Crypt::encryptString($value);
    }

    protected static function decryptSensitive(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            return $value; // legacy plaintext
        }
    }
}
