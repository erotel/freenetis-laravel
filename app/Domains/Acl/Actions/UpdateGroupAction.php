<?php

namespace App\Domains\Acl\Actions;

use App\Domains\Acl\AclService;
use App\Domains\Acl\Repositories\AroGroupRepository;
use App\Models\AroGroup;

/** Přejmenování / přesun skupiny; dědičnost práv se mění → celá ACL cache. */
class UpdateGroupAction
{
    public function __construct(private AroGroupRepository $groups, private AclService $acl)
    {
    }

    public function handle(AroGroup $group, string $name, int $parentId): void
    {
        $this->groups->update($group, $name, $parentId);
        $this->acl->flushAllCache();
    }
}
