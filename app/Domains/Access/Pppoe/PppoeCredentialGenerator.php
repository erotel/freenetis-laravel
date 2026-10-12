<?php

namespace App\Domains\Access\Pppoe;

use App\Domains\Access\Repositories\PppoeCredentialRepository;

/**
 * Generování PPPoE údajů:
 *   - username = variabilní symbol člena; obsazený → suffix `-2`, `-3` …
 *     (credential je per-iface, username musí být unikátní),
 *   - secret = silné náhodné heslo (cleartext kvůli RADIUS CHAP/MS-CHAPv2;
 *     bezpečnost stojí na síle hesla, ne na utajení username).
 */
class PppoeCredentialGenerator
{
    /** Bezpečná abeceda pro heslo: bez 0/O, 1/l/I a znaků, co drhnou v PPP/RouterOS. */
    private const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const SECRET_LEN = 16;

    public function __construct(private PppoeCredentialRepository $credentials)
    {
    }

    /** Unikátní username pro člena. `$exceptIfaceId` = vlastní iface (idempotence). */
    public function usernameFor(int $memberId, ?int $exceptIfaceId = null): string
    {
        $base      = $this->credentials->variableSymbol($memberId);
        $candidate = $base;
        $n = 1;
        while ($this->credentials->usernameTaken($candidate, $exceptIfaceId)) {
            $n++;
            $candidate = $base . '-' . $n;
        }
        return $candidate;
    }

    public function secret(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < self::SECRET_LEN; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }
}
