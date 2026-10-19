<?php

namespace App\Domains\Members\Repositories;

use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Passkeys / biometrické klíče uživatelů (webauthn_credentials, credential_id = base64 raw id). */
class PasskeyRepository
{
    public function forUser(int $userId): Collection
    {
        return WebauthnCredential::where('user_id', $userId)->orderBy('created_at')->get();
    }

    /** Raw (binární) id klíčů uživatele — exclude/allowCredentials. @return string[] */
    public function rawIds(int $userId): array
    {
        return WebauthnCredential::where('user_id', $userId)->pluck('credential_id')
            ->map(fn ($b64) => base64_decode($b64))->filter()->values()->all();
    }

    public function userByLogin(string $login): ?User
    {
        return User::where('login', $login)->first();
    }

    public function hasAny(int $userId): bool
    {
        return WebauthnCredential::where('user_id', $userId)->exists();
    }

    public function exists(string $credentialIdB64): bool
    {
        return WebauthnCredential::where('credential_id', $credentialIdB64)->exists();
    }

    public function find(string $credentialIdB64): ?WebauthnCredential
    {
        return WebauthnCredential::where('credential_id', $credentialIdB64)->first();
    }

    public function create(int $userId, string $credentialIdB64, string $publicKey, int $signCount, string $deviceName): void
    {
        WebauthnCredential::create([
            'user_id'       => $userId,
            'credential_id' => $credentialIdB64,
            'public_key'    => $publicKey,
            'sign_count'    => $signCount,
            'device_name'   => $deviceName,
            'created_at'    => now(),
            'last_used_at'  => null,
        ]);
    }

    /** Smaže klíč jen když patří uživateli. @return bool smazáno */
    public function deleteOwn(int $id, ?int $userId): bool
    {
        return (bool) WebauthnCredential::where('id', $id)->where('user_id', $userId)->delete();
    }

    public function markUsed(WebauthnCredential $credential, int $signCount): void
    {
        $credential->update(['sign_count' => $signCount, 'last_used_at' => now()]);
    }

    public function userWithMember(int $userId): ?User
    {
        return User::with('member')->find($userId);
    }

    public function logLogin(int $userId, ?string $ip): void
    {
        DB::table('login_logs')->insert(['user_id' => $userId, 'time' => now(), 'IP_address' => $ip]);
    }
}
