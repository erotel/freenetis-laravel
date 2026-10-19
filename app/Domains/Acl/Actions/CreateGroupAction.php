<?php

namespace App\Domains\Acl\Actions;

use App\Domains\Acl\Repositories\AroGroupRepository;
use App\Models\AroGroup;

/** Nová skupina (value = jméno malými s podtržítky). Audit přes model. */
class CreateGroupAction
{
    public function __construct(private AroGroupRepository $groups)
    {
    }

    public function handle(string $name, int $parentId): AroGroup
    {
        return $this->groups->create($name, $parentId);
    }
}
