<?php

namespace App\Http\Controllers;

use App\Models\BankStatement;
class BankStatementController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';
    private const ACL_VALUE   = 'bank_statements';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function showByBankAccount(int $bankAccountId)
    {
        abort_unless($this->can('view_all'), 403);

        $statements = BankStatement::where('bank_account_id', $bankAccountId)
            ->with('user')
            ->orderBy('from', 'desc')
            ->paginate(30);

        $account = \App\Models\BankAccount::findOrFail($bankAccountId);

        return view('bank_statements.show_by_account', compact('account', 'statements'));
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('delete'), 403);

        $stmt = BankStatement::with('bankTransfers')->findOrFail($id);
        $bankAccountId = $stmt->bank_account_id;
        $btCount = $stmt->bankTransfers->count();

        // Delete associated bank transfers (soft-delete via model)
        $stmt->bankTransfers()->delete();
        // Hromadné soft-delete přes relaci nespustí per-model eventy — zalogujeme
        // souhrn (kolik bankovních převodů výpis smazal). Samotný BankStatement se
        // auditne přes trait ($stmt->delete()).
        \App\Services\AuditLogger::log('deleted', 'bank_transfers', $id, [
            'bank_statement_id'     => $id,
            'bank_transfers_deleted' => $btCount,
        ], null);
        $stmt->delete();

        return redirect()
            ->route('bank_statements.by_account', $bankAccountId)
            ->with('success', 'Výpis byl smazán.');
    }
}
