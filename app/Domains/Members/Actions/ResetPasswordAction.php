<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Members\Repositories\PasswordResetRepository;
use Carbon\Carbon;

/** Nové heslo podle tokenu z e-mailu (jednorázový, s expirací) + audit. */
class ResetPasswordAction
{
    public function __construct(private PasswordResetRepository $resets)
    {
    }

    /**
     * Uživatel s platným tokenem: existuje, má expiraci (legacy token bez ní
     * se odmítá — nevíme, jak je starý) a nevypršel.
     */
    public function validUser(?string $token): ?object
    {
        if (!$token) {
            return null;
        }
        $user = $this->resets->userByTokenHash(hash('sha256', $token));
        if (!$user || empty($user->password_request_expires_at) || Carbon::parse($user->password_request_expires_at)->isPast()) {
            return null;
        }
        return $user;
    }

    /** @return bool false = neplatný nebo prošlý token */
    public function handle(string $token, string $password): bool
    {
        $user = $this->validUser($token);
        if (!$user) {
            return false;
        }

        $this->resets->setPassword((int) $user->id, bcrypt($password));
        AuditLogger::log('updated', 'users', (int) $user->id, null, ['password' => '***', 'password_reset' => true]);
        // Kdo už byl přihlášen, musí se přihlásit znovu (útočník se session):
        // obecně řeší AuthenticateSession (změněný hash hesla), tady navíc
        // úklid DB sessions, pokud je SESSION_DRIVER=database.
        $this->resets->deleteDatabaseSessions((int) $user->id);

        return true;
    }
}
