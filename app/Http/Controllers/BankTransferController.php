<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Actions\RefundUnidentifiedPaymentAction;
use App\Domains\Billing\Exceptions\OutgoingPaymentRejected;
use App\Domains\Billing\Repositories\BankLedgerRepository;
use Illuminate\Http\Request;

class BankTransferController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';

    public function __construct(private BankLedgerRepository $ledger)
    {
    }

    private function can(string $action, string $value): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, $value);
    }

    public function showByBankAccount(int $bankAccountId)
    {
        abort_unless($this->can('view_all', 'bank_transfers'), 403);

        $account   = $this->ledger->bankAccountOrFail($bankAccountId);
        $transfers = $this->ledger->bankTransfersOfAccount($account, 50);

        return view('bank_transfers.show_by_account', compact('account', 'transfers'));
    }

    public function showUnidentified(Request $request)
    {
        abort_unless($this->can('view_all', 'unidentified_transfers'), 403);

        // Default: posledních 30 dní (po importu produkce je v DB i 4500+ historických
        // neidentifikovaných převodů z pre-migrace, které dělají stránku nepoužitelnou).
        // ?all=1 vypne defaultní rozsah úplně; ?from=… ho přebije konkrétním datem.
        $showAll = $request->boolean('all');
        if ($showAll) {
            $from = null;
        } else {
            $from = $request->filled('from')
                ? $request->query('from')
                : now()->subDays(30)->toDateString();
        }
        $to = $request->filled('to') ? $request->query('to') : null;

        $transfers = $this->ledger->unidentified($from, $to, 50);

        return view('bank_transfers.unidentified', compact('transfers', 'from', 'to'));
    }

    public function refundForm(int $id)
    {
        abort_unless($this->can('edit_all', 'unidentified_transfers'), 403);

        $bt = $this->ledger->bankTransferOrFail($id, ['bankStatement.bankAccount', 'originAccount', 'transfer']);

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

    public function refundStore(int $id, Request $request, RefundUnidentifiedPaymentAction $refund)
    {
        abort_unless($this->can('edit_all', 'unidentified_transfers'), 403);

        $bt = $this->ledger->bankTransferOrFail($id, ['transfer', 'originAccount']);
        abort_if($bt->transfer && $bt->transfer->member_id, 403);

        $validated = $request->validate([
            'bank_account_id' => 'required|integer|exists:bank_accounts,id',
            'amount'          => 'required|numeric|min:0.01',
            'currency'        => 'required|string|size:3',
            'variable_symbol' => 'nullable|string|max:20',
            'message'         => 'nullable|string|max:255',
        ]);

        try {
            $refund->handle($bt, $validated, (int) auth()->id());
        } catch (OutgoingPaymentRejected $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bank_transfers.unidentified')
            ->with('success', 'Odchozí platba (vrácení) byla vytvořena jako koncept.');
    }
}
