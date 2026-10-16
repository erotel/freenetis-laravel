<?php

namespace App\Domains\Contracts\Support;

/** Úložiště PDF smluv a dodatků (services.contracts.storage); cesta v DB je relativní nebo absolutní. */
final class ContractStorage
{
    public static function base(): string
    {
        return rtrim((string) config('services.contracts.storage', '/var/www/contract-app/storage/contracts'), '/');
    }

    /** Plná cesta k souboru, nebo null (není uložen). */
    public static function path(?string $stored): ?string
    {
        if (!$stored) {
            return null;
        }
        return str_starts_with($stored, '/') ? $stored : self::base() . '/' . $stored;
    }
}
