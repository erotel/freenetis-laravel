<?php

namespace App\Domains\Acl\Actions;

use App\Services\AuditLogger;
use App\Services\MfaEnforcementService;

/** MFA fáze B: zda skupina (a její podskupiny) vyžaduje dvoufázové přihlášení. */
class SetGroupMfaRequiredAction
{
    public function __construct(private MfaEnforcementService $mfa)
    {
    }

    public function handle(int $groupId, bool $required): void
    {
        $this->mfa->setGroupRequired($groupId, $required);
        AuditLogger::log('updated', 'mfa_required_groups', $groupId, null, ['group_id' => $groupId, 'required' => $required]);
    }
}
