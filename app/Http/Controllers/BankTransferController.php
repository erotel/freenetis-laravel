<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Services\AclService;
use Illuminate\Http\Request;

class BankTransferController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';

    public function __construct(private AclService $acl) {}

    private function can(string $action, string $value): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, self::ACL_SECTION, $value);
    }

    public function showByBankAccount(int $bankAccountId)
    {
        abort_unless($this->can('view_all', 'bank_transfers'), 403);

        $account = BankAccount::findOrFail($bankAccountId);

        // Get all statement IDs for this account
        $statementIds = $account->bankStatements()->pluck('id');

        $transfers = BankTransfer::whereIn('bank_statement_id', $statementIds)
            ->with(['bankStatement', 'originAccount', 'destinationAccount', 'transfer'])
            ->orderByDesc('id')
            ->paginate(50);

        return view('bank_transfers.show_by_account', compact('account', 'transfers'));
    }

    public function showUnidentified()
    {
        abort_unless($this->can('view_all', 'unidentified_transfers'), 403);

        // Kohana logic: unidentified = transfer exists but member_id is NULL/0
        // (payment could not be matched to any member — wrong account, missing VS, etc.)
        $transfers = BankTransfer::whereNotNull('transfer_id')
            ->whereHas('transfer', function ($q) {
                $q->whereNull('member_id')->orWhere('member_id', 0);
            })
            ->with(['bankStatement.bankAccount', 'originAccount', 'destinationAccount', 'transfer'])
            ->orderByDesc('id')
            ->paginate(50);

        return view('bank_transfers.unidentified', compact('transfers'));
    }
}
