<?php

namespace App\Domains\Billing\Listeners;

use App\Domains\Billing\Events\FeesDeducted;
use App\Services\AuditLogger;

/**
 * Audit trail: souhrn automatického strhávání (cron, user_id=NULL = systém).
 * Jednotlivé postingy jsou v `transfers`; auditujeme běh, ne každý řádek.
 */
class WriteDeductionAudit
{
    public function handle(FeesDeducted $event): void
    {
        AuditLogger::log('fee_deduction', 'transfers', null, null, [
            'date'       => $event->date,
            'deductions' => $event->deductions,
        ]);
    }
}
