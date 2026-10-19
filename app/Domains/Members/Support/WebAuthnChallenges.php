<?php

namespace App\Domains\Members\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Výzva WebAuthn se nedrží v session, ale v cache pod jednorázovým `state`
 * tokenem (TTL 5 min) — odolné vůči potížím se session cookie ve Field PWA;
 * klient si `state` nese mezi options a verify. Výzva je vázaná na účel
 * („login" / „register:{user}"), aby výzva z přihlášení neposloužila
 * k registraci (ta vyžaduje heslo).
 */
final class WebAuthnChallenges
{
    private const TTL = 300;

    /** @return string state token */
    public static function stash(string $binaryChallenge, string $purpose): string
    {
        $state = (string) Str::uuid();
        Cache::put('webauthn:' . $state, ['challenge' => base64_encode($binaryChallenge), 'purpose' => $purpose], self::TTL);
        return $state;
    }

    /** Vyzvedne a zneplatní výzvu; jiný účel = jako by neexistovala. */
    public static function pop(?string $state, string $purpose): ?string
    {
        if (!$state) {
            return null;
        }
        $stored = Cache::pull('webauthn:' . $state);
        if (!is_array($stored) || ($stored['purpose'] ?? null) !== $purpose) {
            return null;
        }
        return base64_decode($stored['challenge']);
    }

    public static function forRegistration(int $userId): string
    {
        return 'register:' . $userId;
    }
}
