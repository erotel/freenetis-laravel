<?php

namespace App\Http\Controllers;

use App\Models\BankStatement;
use App\Services\AclService;

class BankStatementController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';
    private const ACL_VALUE   = 'bank_statements';

    public function __construct(private AclService $acl) {}

    private function can(string $action): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, self::ACL_SECTION, self::ACL_VALUE);
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

        // Delete associated bank transfers (soft-delete via model)
        $stmt->bankTransfers()->delete();
        $stmt->delete();

        return redirect()
            ->route('bank_statements.by_account', $bankAccountId)
            ->with('success', 'Výpis byl smazán.');
    }
}
