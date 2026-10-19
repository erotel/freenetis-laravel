<?php

namespace App\Domains\Network\Support;

/**
 * MAC v zápisech výrobců → tvar v DB `XX:XX:XX:XX:XX:XX`: `50:91:e3:11:29:ad`,
 * `50-91-e3-11-29-ad` (DCN/Linux), `789a-18e2-05db` (Huawei),
 * `5091.e3ad.1129` (Cisco), `5091e3ad1129`. Neúplná MAC → null.
 */
final class MacAddress
{
    public static function normalize(string $input): ?string
    {
        $hex = preg_replace('/[\s:.\-]/', '', $input);
        if ($hex === null || !preg_match('/^[0-9a-fA-F]{12}$/', $hex)) {
            return null;
        }
        return strtoupper(implode(':', str_split($hex, 2)));
    }
}
