<?php

namespace App\Domains\Acl\Actions;

use App\Domains\Acl\AclService;
use App\Domains\Acl\Repositories\AroGroupRepository;
use App\Domains\Audit\AuditLogger;

class UnassignRuleFromGroupAction
{
    public function __construct(private AroGroupRepository $groups, private AclService $acl)
    {
    }

    public function handle(int $groupId, int $aclId): void
    {
        $this->groups->unassignRule($groupId, $aclId);
        AuditLogger::log('deleted', 'aro_groups_map', $groupId, ['group_id' => $groupId, 'acl_id' => $aclId], null);
        $this->acl->flushAllCache();
    }
}
