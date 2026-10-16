<?php

namespace App\Domains\Contracts\Support;

/**
 * Podpisový token: b64url(JSON claims + exp + rnd) . b64url(HMAC-SHA256),
 * tajemství services.contracts.token_secret. Platnost 7 dní.
 *
 * Claims: cid (smlouva), u dodatků navíc aid. Token dodatku tak otevře i
 * stránku smlouvy (/sign/info + OTP) — záměr; typ dodatku token nerozlišuje,
 * hlídá ho až načtení dodatku podle typu.
 */
final class SignLinkToken
{
    public const TTL_SECONDS = 7 * 86400;

    private string $secret;

    public function __construct()
    {
        $this->secret = (string) config('services.contracts.token_secret', '');

        // Prázdný/krátký/placeholder secret = podvrhnutelné tokeny. `openssl rand -hex 32` = 64 znaků; min. 32.
        if (strlen($this->secret) < 32 || $this->secret === 'long_random_hex_64') {
            throw new \RuntimeException(
                'CONTRACTS_TOKEN_SECRET není nastaven nebo je příliš krátký (min. 32 znaků). '
                . 'Vygeneruj např. `openssl rand -hex 32` a nastav v .env, pak spusť `php artisan config:cache`.'
            );
        }
    }

    /** @param array{cid:int, aid?:int} $claims */
    public function issue(array $claims): string
    {
        $json = json_encode($claims + [
            'exp' => time() + self::TTL_SECONDS,
            'rnd' => bin2hex(random_bytes(8)),
        ], JSON_UNESCAPED_SLASHES);

        return self::b64url($json) . '.' . self::b64url(hash_hmac('sha256', $json, $this->secret, true));
    }

    /** Ověří podpis a platnost. @return ?array claims, nebo null (neplatný/expirovaný/podvržený) */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return null;

        $json = self::b64urlDec($parts[0]);
        $sig  = self::b64urlDec($parts[1]);
        if ($json === '' || $sig === '') return null;

        if (!hash_equals(hash_hmac('sha256', $json, $this->secret, true), $sig)) return null;

        $data = json_decode($json, true);
        if (!is_array($data) || ((int) ($data['exp'] ?? 0)) < time()) return null;

        return $data;
    }

    /** id smlouvy z platného tokenu, nebo null. */
    public function contractId(string $token): ?int
    {
        $cid = (int) ($this->verify($token)['cid'] ?? 0);
        return $cid > 0 ? $cid : null;
    }

    /** id dodatku z platného tokenu, nebo null. */
    public function addonId(string $token): ?int
    {
        $aid = (int) ($this->verify($token)['aid'] ?? 0);
        return $aid > 0 ? $aid : null;
    }

    /** Zkrácený token do logu událostí (nikdy celý). */
    public static function preview(string $token): string
    {
        return substr($token, 0, 20) . '…';
    }

    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64urlDec(string $s): string
    {
        $r = base64_decode(strtr($s, '-_', '+/'));
        return $r === false ? '' : $r;
    }
}
