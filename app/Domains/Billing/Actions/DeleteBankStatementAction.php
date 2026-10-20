<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Billing\Exceptions\BankStatementRejected;
use App\Domains\Billing\Repositories\BankLedgerRepository;
use App\Models\BankStatement;

/**
 * Smaže bankovní výpis i s jeho pohyby (soft-delete) a zaloguje souhrn.
 *
 * Jen dokud z něj nic není zaúčtované: smazání nevrací připsané kredity
 * a import (deduplikace podle kódu pohybu ignoruje smazané) by tytéž
 * platby připsal podruhé.
 */
class DeleteBankStatementAction
{
    public function __construct(private BankLedgerRepository $ledger)
    {
    }

    /** @throws BankStatementRejected */
    public function handle(BankStatement $statement): void
    {
        if ($posted = $this->ledger->postedTransferCount($statement)) {
            throw new BankStatementRejected("Výpis nelze smazat — {$posted} jeho plateb je zaúčtovaných na účtech. Smazáním by se kredity nevrátily a nový import by je připsal znovu.");
        }

        $id = (int) $statement->id;

        // Hromadné soft-delete přes relaci nespustí per-model eventy — zalogujeme
        // souhrn (kolik bankovních převodů výpis smazal). Samotný BankStatement se
        // auditne přes trait.
        $count = $this->ledger->deleteStatement($statement);
        AuditLogger::log('deleted', 'bank_transfers', $id, [
            'bank_statement_id'      => $id,
            'bank_transfers_deleted' => $count,
        ], null);
    }
}
