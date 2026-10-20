<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Auth\WebAuthnService;
use App\Domains\Members\Exceptions\PasskeyRejected;
use App\Domains\Members\Repositories\PasskeyRepository;
use App\Domains\Members\Support\WebAuthnChallenges;
use lbuchs\WebAuthn\Binary\ByteBuffer;

/** Ověří odpověď authenticatoru na výzvu a uloží nový passkey uživatele (attestation se neověřuje). */
class RegisterPasskeyAction
{
    public function __construct(private PasskeyRepository $passkeys, private WebAuthnService $svc)
    {
    }

    /**
     * @return ?string hláška, když klíč už registrovaný byl (jinak null)
     * @throws PasskeyRejected
     */
    public function handle(int $userId, string $state, string $clientDataJson, string $attestationObject, ?string $deviceName): ?string
    {
        $challenge = WebAuthnChallenges::pop($state, WebAuthnChallenges::forRegistration($userId));
        if (!$challenge) {
            throw new PasskeyRejected('Vypršela platnost výzvy. Zkuste to znovu.');
        }

        try {
            $result = $this->svc->make()->processCreate(
                base64_decode($clientDataJson),
                base64_decode($attestationObject),
                new ByteBuffer($challenge),
                $this->svc->userVerification() === 'required',
                true,   // requireUserPresent
                false   // failIfRootMismatch — attestation nevyžadujeme
            );
        } catch (\Throwable $e) {
            throw new PasskeyRejected('Registraci se nepodařilo ověřit: ' . $e->getMessage());
        }

        $credentialId = base64_encode($result->credentialId instanceof ByteBuffer ? $result->credentialId->getBinaryString() : $result->credentialId);
        if ($this->passkeys->exists($credentialId)) {
            return 'Toto zařízení už je registrované.';
        }

        $this->passkeys->create($userId, $credentialId, $result->credentialPublicKey, (int) ($result->signatureCounter ?? 0),
            trim($deviceName ?? '') ?: 'Neznámé zařízení');
        return null;
    }
}
