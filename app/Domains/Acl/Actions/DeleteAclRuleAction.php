<?php

namespace App\Domains\Acl\Actions;

use App\Domains\Acl\AclService;
use App\Domains\Acl\Repositories\AclRuleRepository;
use App\Domains\Audit\AuditLogger;

class DeleteAclRuleAction
{
    public function __construct(private AclRuleRepository $rules, private AclService $acl)
    {
    }

    public function handle(int $id): void
    {
        $old = $this->rules->note($id);
        $this->rules->delete($id);
        AuditLogger::log('deleted', 'acl', $id, ['note' => $old], null);
        $this->acl->flushAllCache();
    }
}
