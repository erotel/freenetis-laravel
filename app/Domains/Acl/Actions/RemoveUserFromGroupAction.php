<?php

namespace App\Domains\Acl\Actions;

use App\Domains\Acl\AclService;
use App\Domains\Acl\Repositories\AroGroupRepository;
use App\Domains\Audit\AuditLogger;

class RemoveUserFromGroupAction
{
    public function __construct(private AroGroupRepository $groups, private AclService $acl)
    {
    }

    public function handle(int $groupId, int $userId): void
    {
        $this->groups->removeUser($groupId, $userId);
        AuditLogger::log('deleted', 'groups_aro_map', $groupId, ['group_id' => $groupId, 'user_id' => $userId], null);
        $this->acl->flushUserCache($userId);
    }
}
