<?php

namespace App\Models\Concerns;

/**
 * Doplněk k {@see Auditable}: auditovat JEN lidské zásahy.
 *
 * Automatické operace bez přihlášeného uživatele (cron/console — import
 * bankovních výpisů, DeductFees, posting do ledgeru, hromadná fakturace) se do
 * `audit_logs` nepíšou per-řádek: ledger je self-dokumentující a hromadné
 * procesy mají vlastní SOUHRNNÝ audit (viz AuditLogger volání v příslušných
 * službách). Per-řádek by jen zahltily partitionovaný audit_logs a utopily
 * bezpečnostně relevantní lidské akce.
 *
 * Lidská úprava/smazání (tamper/fraud) se loguje dál — pozná se podle
 * přihlášeného uživatele.
 */
trait AuditsHumanActionsOnly
{
    public function auditShouldSkip(string $action): bool
    {
        return !auth()->check();
    }
}
