<?php

namespace App\Domains\Access\Support;

/**
 * Identifikátor konzumenta DHCP exportu (?client=...) — omezený na bezpečné
 * znaky a délku, ať se dá použít jako klíč v dhcp_export_state.
 */
final class DhcpClientId
{
    /** Null = žádný (nebo po sanitizaci prázdný) klient → legacy režim. */
    public static function normalize(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $raw = preg_replace('/[^A-Za-z0-9_.\-]/', '', trim($raw));
        return ($raw === null || $raw === '') ? null : substr($raw, 0, 64);
    }
}
