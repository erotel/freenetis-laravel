<?php

namespace App\Domains\Acl\Actions;

use App\Domains\Acl\Repositories\AclRuleRepository;
use App\Services\AclService;
use App\Services\AuditLogger;

/** Založí / přepíše pravidlo (poznámka, akce, objekty, skupiny) + audit + ACL cache. */
class SaveAclRuleAction
{
    public function __construct(private AclRuleRepository $rules, private AclService $acl)
    {
    }

    /**
     * @param ?int $id null = nové pravidlo
     * @return int id pravidla
     */
    public function handle(?int $id, string $note, array $actions, array $axoIds, array $groupIds): int
    {
        $new = ['note' => $note, 'actions' => $actions, 'axo_ids' => $axoIds, 'group_ids' => $groupIds];

        if ($id === null) {
            $id = $this->rules->create($note);
            $this->rules->replaceMappings($id, $actions, $axoIds, $groupIds);
            AuditLogger::log('created', 'acl', $id, null, $new);
        } else {
            $old = $this->rules->note($id);
            $this->rules->setNote($id, $note);
            AuditLogger::log('updated', 'acl', $id, ['note' => $old], $new);
            $this->rules->replaceMappings($id, $actions, $axoIds, $groupIds);
        }

        $this->acl->flushAllCache();
        return $id;
    }
}
