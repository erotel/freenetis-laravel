<?php

namespace App\Domains\Members\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Výzva WebAuthn se nedrží v session, ale v cache pod jednorázovým `state`
 * tokenem (TTL 5 min) — odolné vůči potížím se session cookie ve Field PWA;
 * klient si `state` nese mezi options a verify.
 */
final class WebAuthnChallenges
{
    private const TTL = 300;

    /** @return string state token */
    public static function stash(string $binaryChallenge): string
    {
        $state = (string) Str::uuid();
        Cache::put('webauthn:' . $state, base64_encode($binaryChallenge), self::TTL);
        return $state;
    }

    /** Vyzvedne a zneplatní výzvu. */
    public static function pop(?string $state): ?string
    {
        if (!$state) {
            return null;
        }
        $b64 = Cache::pull('webauthn:' . $state);
        return $b64 ? base64_decode($b64) : null;
    }
}
