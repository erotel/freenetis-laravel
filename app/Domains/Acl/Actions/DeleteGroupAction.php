<?php

namespace App\Domains\Acl\Actions;

use App\Domains\Acl\AclService;
use App\Domains\Acl\Exceptions\GroupNotDeletable;
use App\Domains\Acl\Repositories\AroGroupRepository;
use App\Models\AroGroup;

/** Smaže prázdnou skupinu (bez podskupin a uživatelů) i její přiřazení pravidel. */
class DeleteGroupAction
{
    public function __construct(private AroGroupRepository $groups, private AclService $acl)
    {
    }

    /** @throws GroupNotDeletable */
    public function handle(AroGroup $group): void
    {
        if ($this->groups->hasChildren($group)) {
            throw new GroupNotDeletable('Nelze smazat skupinu, která má podskupiny.');
        }
        if ($this->groups->hasUsers((int) $group->id)) {
            throw new GroupNotDeletable('Nelze smazat skupinu, která má přiřazené uživatele.');
        }
        $this->groups->delete($group);
        $this->acl->flushAllCache();
    }
}
