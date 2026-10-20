<?php

namespace App\Domains\Acl\Actions;

use App\Domains\Acl\AclService;
use App\Domains\Acl\Repositories\AroGroupRepository;
use App\Domains\Audit\AuditLogger;

/** Přidá uživatele do skupiny (opakovaně nic) + audit + cache uživatele. */
class AddUserToGroupAction
{
    public function __construct(private AroGroupRepository $groups, private AclService $acl)
    {
    }

    public function handle(int $groupId, int $userId): void
    {
        if (!$this->groups->addUser($groupId, $userId)) {
            return;
        }
        AuditLogger::log('created', 'groups_aro_map', $groupId, null, ['group_id' => $groupId, 'user_id' => $userId]);
        $this->acl->flushUserCache($userId);
    }
}
