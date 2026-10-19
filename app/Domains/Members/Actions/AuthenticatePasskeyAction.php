<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Exceptions\PasskeyRejected;
use App\Domains\Members\Repositories\PasskeyRepository;
use App\Domains\Members\Support\WebAuthnChallenges;
use App\Models\User;
use App\Services\WebAuthnService;
use lbuchs\WebAuthn\Binary\ByteBuffer;

/**
 * Ověří přihlášení passkey: platná výzva, známý klíč, podpis, čítač (nový
 * musí být > uloženého, jinak podezření na klon), člen nezamčený (stejně
 * jako heslové cesty). Úspěch = aktualizuje čítač + login_logs; session
 * zakládá volající.
 */
class AuthenticatePasskeyAction
{
    public function __construct(private PasskeyRepository $passkeys, private WebAuthnService $svc)
    {
    }

    /** @throws PasskeyRejected */
    public function handle(string $credentialIdB64, string $state, string $clientDataJson, string $authenticatorData, string $signature, ?string $ip): User
    {
        $challenge = WebAuthnChallenges::pop($state, 'login');
        if (!$challenge) {
            throw new PasskeyRejected('Vypršela platnost výzvy. Zkuste to znovu.');
        }
        $credential = $this->passkeys->find($credentialIdB64);
        if (!$credential) {
            throw new PasskeyRejected('Neznámé biometrické zařízení.');
        }

        try {
            $webAuthn = $this->svc->make();
            $webAuthn->processGet(
                base64_decode($clientDataJson),
                base64_decode($authenticatorData),
                base64_decode($signature),
                $credential->public_key,
                new ByteBuffer($challenge),
                $credential->sign_count > 0 ? $credential->sign_count : null,
                $this->svc->userVerification() === 'required',
                true
            );
        } catch (\Throwable $e) {
            logger()->warning('auth.webauthn.failed', ['credential_id' => substr($credentialIdB64, 0, 16) . '…', 'ip' => $ip, 'reason' => $e->getMessage()]);
            throw new PasskeyRejected('Ověření se nezdařilo: ' . $e->getMessage());
        }

        $newCount = (int) $webAuthn->getSignatureCounter();
        if ($credential->sign_count > 0 && $newCount > 0 && $newCount <= $credential->sign_count) {
            logger()->warning('auth.webauthn.clone_suspected', [
                'user_id' => $credential->user_id, 'ip' => $ip, 'stored' => $credential->sign_count, 'received' => $newCount,
            ]);
            throw new PasskeyRejected('Bezpečnostní kontrola selhala (možná klonovaný klíč).');
        }

        $user = $this->passkeys->userWithMember((int) $credential->user_id);
        if (!$user) {
            throw new PasskeyRejected('Uživatel neexistuje.');
        }
        // zamčený člen se nesmí přihlásit ani passkey (heslové cesty ho blokují v provideru)
        if ($user->member && $user->member->locked) {
            logger()->warning('auth.webauthn.locked_member', ['user_id' => $user->id, 'ip' => $ip]);
            throw new PasskeyRejected('Účet je zablokován.');
        }

        $this->passkeys->markUsed($credential, $newCount);
        $this->passkeys->logLogin((int) $user->id, $ip);
        return $user;
    }
}
