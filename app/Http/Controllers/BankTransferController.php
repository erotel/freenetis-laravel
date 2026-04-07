<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\OutgoingPayment;
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

    public function refundForm(int $id)
    {
        abort_unless($this->can('edit_all', 'unidentified_transfers'), 403);

        $bt = BankTransfer::with(['bankStatement.bankAccount', 'originAccount', 'transfer'])->findOrFail($id);

        // Must be unidentified (member_id null/0)
        abort_if($bt->transfer && $bt->transfer->member_id, 403);

        $transfer    = $bt->transfer;
        $bankAccount = $bt->bankStatement->bankAccount;

        $prefill = [
            'bank_account_id' => $bankAccount->id,
            'target_account'  => $bt->originAccount
                ? $bt->originAccount->account_nr . '/' . $bt->originAccount->bank_nr
                : '',
            'target_name'     => $bt->originAccount->name ?? '',
            'amount'          => $transfer ? abs($transfer->amount) : 0,
            'currency'        => 'CZK',
            'variable_symbol' => $bt->variable_symbol ?? '',
            'message'         => 'Vrácení neidentifikované platby #' . $bt->transfer_id,
            'reason'          => 'unidentified_refund',
        ];

        return view('bank_transfers.refund_form', compact('bt', 'bankAccount', 'prefill'));
    }

    public function refundStore(int $id, Request $request)
    {
        abort_unless($this->can('edit_all', 'unidentified_transfers'), 403);

        $bt = BankTransfer::with(['transfer'])->findOrFail($id);
        abort_if($bt->transfer && $bt->transfer->member_id, 403);

        $validated = $request->validate([
            'bank_account_id' => 'required|integer|exists:bank_accounts,id',
            'target_account'  => 'required|string|max:50',
            'target_name'     => 'nullable|string|max:255',
            'amount'          => 'required|numeric|min:0.01',
            'currency'        => 'required|string|size:3',
            'variable_symbol' => 'nullable|string|max:20',
            'message'         => 'nullable|string|max:255',
        ]);

        // Check if refund already exists for this transfer
        $existing = OutgoingPayment::where('transfer_id', $bt->transfer_id)->first();
        if ($existing) {
            return back()->with('error', 'Pro tento převod již existuje odchozí platba (stav: ' . $existing->status . ').');
        }

        OutgoingPayment::create([
            'bank_account_id' => $validated['bank_account_id'],
            'transfer_id'     => $bt->transfer_id,
            'target_account'  => $validated['target_account'],
            'target_name'     => $validated['target_name'] ?? '',
            'amount'          => $validated['amount'],
            'currency'        => $validated['currency'],
            'variable_symbol' => $validated['variable_symbol'] ?? '',
            'message'         => $validated['message'] ?? '',
            'reason'          => 'unidentified_refund',
            'status'          => 'draft',
            'created_by'      => auth()->id(),
        ]);

        return redirect()->route('bank_transfers.unidentified')
            ->with('success', 'Odchozí platba (vrácení) byla vytvořena jako koncept.');
    }
}
