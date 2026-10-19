<?php

namespace App\Domains\Acl\Actions;

use App\Domains\Acl\Repositories\AroGroupRepository;
use App\Services\AclService;
use App\Services\AuditLogger;

/** Přiřadí pravidlo skupině (opakovaně nic) + audit + celá ACL cache. */
class AssignRuleToGroupAction
{
    public function __construct(private AroGroupRepository $groups, private AclService $acl)
    {
    }

    public function handle(int $groupId, int $aclId): void
    {
        if (!$this->groups->assignRule($groupId, $aclId)) {
            return;
        }
        AuditLogger::log('created', 'aro_groups_map', $groupId, null, ['group_id' => $groupId, 'acl_id' => $aclId]);
        $this->acl->flushAllCache();
    }
}
