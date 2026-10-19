<?php

namespace App\Domains\Members\Support;

/** Zobrazovací pomůcky Field Mode: stavová tečka člena a jednořádková adresa. */
final class FieldPresenter
{
    /** suspended > terminating > blocked > overdue > ok */
    public static function status(?bool $locked, ?float $balance, ?bool $paymentBlocked = false, ?bool $pendingTermination = false): string
    {
        if ($locked) {
            return 'suspended';
        }
        if ($pendingTermination) {
            return 'terminating';
        }
        if ($paymentBlocked) {
            return 'blocked';
        }
        if ($balance !== null && $balance < 0) {
            return 'overdue';
        }
        return 'ok';
    }

    /** „ulice číslo, obec" (chybějící části vynechá), nic → null. */
    public static function address(?string $street, ?string $number, ?string $town): ?string
    {
        $line  = trim(trim((string) $street) . ' ' . trim((string) $number));
        $parts = array_filter([$line !== '' ? $line : null, $town ?: null]);
        return $parts ? implode(', ', $parts) : null;
    }
}
