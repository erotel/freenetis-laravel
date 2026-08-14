<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\OutgoingPayment;
use Illuminate\Http\Request;

class BankTransferController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';

    private function can(string $action, string $value): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, $value);
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

        // Kohana logic (Bank_transfer_Model::get_unidentified_transfers):
        //   neidentifikovaný = transfer.member_id IS NULL/0
        //   AND origin účet je typu MEMBER_FEES (684000) — suspense účet, na který se
        //   předúčtují příchozí bankovní platby, dokud nejsou přiřazené členovi.
        //   Bez tohoto filtru sem padají i interní převody (banka → dodavatelé, atd.).
        $transfers = BankTransfer::whereNotNull('bank_transfers.transfer_id')
            ->join('transfers', 'transfers.id', '=', 'bank_transfers.transfer_id')
            ->join('accounts as origin_acc', 'origin_acc.id', '=', 'transfers.origin_id')
            ->where('origin_acc.account_attribute_id', 684000)
            ->where(function ($q) {
                $q->whereNull('transfers.member_id')->orWhere('transfers.member_id', 0);
            })
            ->when($from, fn($q) => $q->where('transfers.datetime', '>=', $from . ' 00:00:00'))
            ->when($to,   fn($q) => $q->where('transfers.datetime', '<=', $to   . ' 23:59:59'))
            ->with(['bankStatement.bankAccount', 'originAccount', 'destinationAccount', 'transfer'])
            ->orderByDesc('transfers.datetime')
            ->select('bank_transfers.*')
            ->paginate(50)
            ->withQueryString();

        return view('bank_transfers.unidentified', compact('transfers', 'from', 'to'));
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

        $bt = BankTransfer::with(['transfer', 'originAccount'])->findOrFail($id);
        abort_if($bt->transfer && $bt->transfer->member_id, 403);

        $validated = $request->validate([
            'bank_account_id' => 'required|integer|exists:bank_accounts,id',
            'amount'          => 'required|numeric|min:0.01',
            'currency'        => 'required|string|size:3',
            'variable_symbol' => 'nullable|string|max:20',
            'message'         => 'nullable|string|max:255',
        ]);

        // Vrácení neidentifikované platby smí jít VÝHRADNĚ zpět odesílateli a
        // NEJVÝŠ v přijaté částce. Cílový účet i jméno proto odvozujeme ze
        // serveru (z origin účtu přijaté platby), NE z requestu — jinak by šlo
        // poslat libovolnou částku na libovolný účet (arbitrary payout).
        if (!$bt->transfer) {
            return back()->with('error', 'K tomuto bankovnímu pohybu chybí účetní převod — vrácení nelze provést.');
        }
        $origin = $bt->originAccount;
        if (!$origin || !$origin->account_nr) {
            return back()->with('error', 'Neznámý odesílatel platby — vrácení nelze bezpečně provést (chybí protiúčet).');
        }

        $maxAmount = round(abs((float) $bt->transfer->amount), 2);
        if (round((float) $validated['amount'], 2) > $maxAmount) {
            return back()
                ->withInput()
                ->with('error', 'Vrácená částka nesmí převýšit přijatou platbu (' . number_format($maxAmount, 2, ',', ' ') . ' Kč).');
        }

        $targetAccount = $origin->account_nr . '/' . $origin->bank_nr;
        $targetName    = $origin->name ?? '';

        // Check if refund already exists for this transfer
        $existing = OutgoingPayment::where('transfer_id', $bt->transfer_id)->first();
        if ($existing) {
            return back()->with('error', 'Pro tento převod již existuje odchozí platba (stav: ' . $existing->status . ').');
        }

        OutgoingPayment::create([
            'bank_account_id' => $validated['bank_account_id'],
            'transfer_id'     => $bt->transfer_id,
            'target_account'  => $targetAccount,
            'target_name'     => $targetName,
            'amount'          => $validated['amount'],
            'currency'        => $validated['currency'],
            'variable_symbol' => $validated['variable_symbol'] ?? '',
            'message'         => $validated['message'] ?? '',
            'reason'          => 'unidentified_refund',
            'status'          => 'draft',
            'created_by'      => auth()->id(),
        ]);

        \App\Services\AuditLogger::log('created', 'outgoing_payments', (int) $bt->transfer_id, null, [
            'reason'         => 'unidentified_refund',
            'amount'         => $validated['amount'],
            'target_account' => $targetAccount,
        ]);

        return redirect()->route('bank_transfers.unidentified')
            ->with('success', 'Odchozí platba (vrácení) byla vytvořena jako koncept.');
    }
}
