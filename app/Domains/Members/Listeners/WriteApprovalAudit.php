<?php

namespace App\Domains\Members\Listeners;

use App\Domains\Audit\AuditLogger;
use App\Domains\Members\Events\MemberApproved;

/**
 * Zapíše schválení jako samostatnou auditní akci 'approved' (NIS2 audit trail).
 * Doplňuje obecný 'updated' záznam z Auditable traitu — schválení tak jde
 * v auditu dohledat filtrem podle akce.
 */
class WriteApprovalAudit
{
    public function handle(MemberApproved $event): void
    {
        AuditLogger::log(
            'approved',
            'members',
            $event->memberId,
            ['type' => $event->previousType],
            ['type' => $event->newType],
        );
    }
}
