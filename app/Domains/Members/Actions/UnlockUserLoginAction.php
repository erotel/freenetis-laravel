<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Members\Auth\LoginLockService;

/** Ruční odemčení účtu zamčeného po neúspěšných přihlášeních (audit + log). */
class UnlockUserLoginAction
{
    public function __construct(private LoginLockService $lock)
    {
    }

    public function handle(int $userId, ?int $actorId, ?string $ip): void
    {
        $this->lock->unlock($userId);
        AuditLogger::log('login_unlock', 'users', $userId, null, ['by_user_id' => $actorId]);
        logger()->info('auth.account.admin_unlock', ['user_id' => $userId, 'by' => $actorId, 'ip' => $ip]);
    }
}
