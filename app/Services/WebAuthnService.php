<?php

namespace App\Services;

use lbuchs\WebAuthn\WebAuthn;

/**
 * Tenká obálka nad lbuchs/webauthn. Drží RP konfiguraci a vytváří
 * nakonfigurované WebAuthn instance. Vlastní credentials spravujeme
 * v tabulce webauthn_credentials (model WebauthnCredential).
 */
class WebAuthnService
{
    public function rpId(): string
    {
        return (string) config('webauthn.rp_id');
    }

    public function rpName(): string
    {
        return (string) config('webauthn.rp_name');
    }

    /** 'required' | 'preferred' | 'discouraged' */
    public function userVerification(): string
    {
        return (string) config('webauthn.user_verification', 'required');
    }

    /**
     * Nová WebAuthn instance. useBase64UrlEncoding=true → binární pole v JSON
     * argumentech (challenge, user.id, allow/excludeCredentials[].id) jdou ven
     * jako base64url stringy, které frontend snadno dekóduje na ArrayBuffer.
     *
     * $allowedFormats=null → akceptuj všechny attestation formáty (attestation
     * nevyžadujeme/neověřujeme proti rootům — viz failIfRootMismatch=false).
     */
    public function make(): WebAuthn
    {
        return new WebAuthn($this->rpName(), $this->rpId(), null, true);
    }
}
