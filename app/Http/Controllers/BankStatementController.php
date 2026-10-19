<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Actions\DeleteBankStatementAction;
use App\Domains\Billing\Repositories\BankLedgerRepository;

class BankStatementController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';
    private const ACL_VALUE   = 'bank_statements';

    public function __construct(private BankLedgerRepository $ledger)
    {
    }

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function showByBankAccount(int $bankAccountId)
    {
        abort_unless($this->can('view_all'), 403);

        $statements = $this->ledger->statementsOfAccount($bankAccountId, 30);
        $account = $this->ledger->bankAccountOrFail($bankAccountId);

        return view('bank_statements.show_by_account', compact('account', 'statements'));
    }

    public function destroy(int $id, DeleteBankStatementAction $delete)
    {
        abort_unless($this->can('delete'), 403);

        $stmt = $this->ledger->statementWithTransfersOrFail($id);
        $delete->handle($stmt);

        return redirect()
            ->route('bank_statements.by_account', $stmt->bank_account_id)
            ->with('success', 'Výpis byl smazán.');
    }
}
