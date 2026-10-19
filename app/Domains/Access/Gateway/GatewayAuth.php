<?php

namespace App\Domains\Access\Gateway;

use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Autentizace M2M exportů pro brány (NIS2/ZoKB):
 *  1. platný web_interface_api_token (Bearer, jinak ?token= pro legacy MikroTiky
 *     — query se propisuje do logů, CWE-598) → odkudkoli;
 *  2. vynucení tokenu (web_interface_require_token) → bez tokenu jen localhost;
 *  3. jinak (permisivní fáze) IP allowlist address_ranges.
 * Odmítnutí se loguje (web_interface.access_denied).
 */
final class GatewayAuth
{
    public static function allows(Request $request): bool
    {
        if (self::hasValidToken($request)) {
            return true;
        }
        if (Setting::get('web_interface_require_token', 0)) {
            if (in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
                return true;
            }
            self::logRejected($request, 'missing_or_invalid_token');
            return false;
        }
        if (!self::fromTrustedRange((string) $request->ip())) {
            self::logRejected($request, 'untrusted_ip');
            return false;
        }
        return true;
    }

    /** Exporty přesměrování navíc jen se zapnutým přesměrováním. */
    public static function redirectionEnabled(): bool
    {
        return Setting::get('redirection_enabled', '0') == '1';
    }

    private static function hasValidToken(Request $request): bool
    {
        $token = $request->bearerToken() ?? $request->input('token');
        $valid = (string) Setting::get('web_interface_api_token', '');
        return $token !== null && $token !== '' && $valid !== '' && hash_equals($valid, (string) $token);
    }

    private static function fromTrustedRange(string $ip): bool
    {
        foreach (explode(',', (string) Setting::get('address_ranges', '')) as $cidr) {
            $cidr = trim($cidr);
            if ($cidr !== '' && self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = $bits >= 32 ? 0xFFFFFFFF : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private static function logRejected(Request $request, string $reason): void
    {
        logger()->warning('web_interface.access_denied', ['reason' => $reason, 'ip' => $request->ip(), 'path' => $request->path()]);
    }
}
