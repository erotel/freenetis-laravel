<?php

namespace App\Support;

/**
 * Buňka CSV pro Excel/LibreOffice: text začínající = + - @ (nebo tab/CR)
 * by se při otevření vyhodnotil jako vzorec (CSV/formula injection — např.
 * jméno z veřejné registrace „=HYPERLINK(…)“). Takovému textu se předřadí
 * apostrof; čísla (i záporná) zůstanou čísly.
 */
final class CsvCell
{
    public static function safe(mixed $value): mixed
    {
        if (!is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }
        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    /** @param array<int, mixed> $row */
    public static function row(array $row): array
    {
        return array_map(self::safe(...), $row);
    }
}
