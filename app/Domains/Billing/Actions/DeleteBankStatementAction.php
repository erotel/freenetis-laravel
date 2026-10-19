<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Repositories\BankLedgerRepository;
use App\Models\BankStatement;
use App\Services\AuditLogger;

/** Smaže bankovní výpis i s jeho pohyby (soft-delete) a zaloguje souhrn. */
class DeleteBankStatementAction
{
    public function __construct(private BankLedgerRepository $ledger)
    {
    }

    public function handle(BankStatement $statement): void
    {
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
