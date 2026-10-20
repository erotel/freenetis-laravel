<?php

namespace App\Domains\Members\Listeners;

use App\Domains\Audit\AuditLogger;
use App\Domains\Members\Events\MemberPurged;

/** Audit trvalého smazání: action 'deleted', původní řádek člena. */
class WritePurgeAudit
{
    public function handle(MemberPurged $event): void
    {
        AuditLogger::log('deleted', 'members', $event->memberId, $event->snapshot, null);
    }
}
