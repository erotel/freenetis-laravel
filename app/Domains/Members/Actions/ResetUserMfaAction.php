<?php

namespace App\Domains\Members\Actions;

use App\Services\AuditLogger;
use App\Services\MfaService;

/**
 * Admin reset cizího MFA (ztracený telefon i záložní kódy): smaže TOTP
 * i záložní kódy; uživatel si ho zřídí znovu. Audit + log.
 */
class ResetUserMfaAction
{
    public function __construct(private MfaService $mfa)
    {
    }

    public function handle(int $userId, ?int $actorId, ?string $ip): void
    {
        $this->mfa->disable($userId);
        AuditLogger::log('mfa_reset', 'users', $userId, null, ['by_user_id' => $actorId]);
        logger()->warning('auth.mfa.admin_reset', ['user_id' => $userId, 'by' => $actorId, 'ip' => $ip]);
    }
}
