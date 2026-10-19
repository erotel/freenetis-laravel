<?php

namespace App\Domains\Acl\Queries;

use App\Domains\Acl\Repositories\AclRuleRepository;
use App\Domains\Acl\Repositories\AroGroupRepository;
use App\Services\MfaEnforcementService;

/** Karta skupiny: pravidla, členové, kandidáti k přidání, povinné MFA. */
class GroupCardQuery
{
    public function __construct(
        private AroGroupRepository $groups,
        private AclRuleRepository $rules,
        private MfaEnforcementService $mfa,
    ) {
    }

    public function find(int $id): array
    {
        $group    = $this->groups->findWithFamilyOrFail($id);
        $aclRules = $this->rules->forGroup($id);
        $users    = $this->groups->users($id);

        return [
            'group'       => $group,
            'aclRules'    => $aclRules,
            'users'       => $users,
            'allUsers'    => $this->groups->usersExcept($users->pluck('id')->toArray()),
            'allAcls'     => $this->rules->allExcept($aclRules->keys()->toArray()),
            'mfaRequired' => $this->mfa->isGroupRequired($id),
        ];
    }
}
