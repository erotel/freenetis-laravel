<?php

namespace App\Domains\Billing\Actions;

use App\Models\BankAccount;

/** Import nových pohybů FIO bez HTTP kontextu (plánovač bank:import-statements). */
class ImportNewFioPaymentsAction
{
    public function __construct(
        private FetchFioStatementAction $fetch,
        private ImportBankStatementAction $import,
    ) {
    }

    /**
     * @return array{imported:int, skipped:int}
     * @throws \RuntimeException neznámý účet / chybí token / chyba API
     */
    public function handle(int $bankAccountId, string $source = 'FIO API scheduler'): array
    {
        $account = BankAccount::find($bankAccountId);
        if (!$account) {
            throw new \RuntimeException("Bank account #{$bankAccountId} not found.");
        }
        $token = FetchFioStatementAction::token($bankAccountId);
        if (empty($token)) {
            throw new \RuntimeException("No FIO API token configured for bank account #{$bankAccountId}.");
        }

        $parsed = $this->fetch->newSince($account, $token);
        if (empty($parsed['rows'])) {
            return ['imported' => 0, 'skipped' => 0];
        }

        [$imported, $skipped] = $this->import->handle($account, $parsed['header'], $parsed['rows'], $source, auth()->id() ?? 1);

        return ['imported' => $imported, 'skipped' => $skipped];
    }
}
