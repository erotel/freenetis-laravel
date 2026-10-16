<?php

namespace App\Domains\Billing\Bank;

use App\Domains\Billing\Repositories\BankLedgerRepository;
use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\OutgoingPayment;
use Illuminate\Support\Facades\DB;

/**
 * Spárování odchozího pohybu s odchozí platbou podle „OP #id" v identifikaci
 * (Kohana-kompatibilní): stejný bankovní účet, stav exportováno/schváleno,
 * částka ±0,01. Spárovaná → zaplaceno; původní neidentifikovaná platba
 * (vratka) dostane poznámku a přesune se organizaci.
 */
class OutgoingPaymentReconciler
{
    public function __construct(private BankLedgerRepository $ledger)
    {
    }

    public function reconcile(BankTransfer $bt, BankAccount $account, string $identification, float $amount): void
    {
        if ($identification === '' || !preg_match('~\bOP\s*#\s*([0-9]+)\b~i', $identification, $m)) {
            return;
        }

        $match = OutgoingPayment::where('id', (int) $m[1])
            ->where('bank_account_id', $account->id)
            ->whereIn('status', [OutgoingPayment::STATUS_EXPORTED, OutgoingPayment::STATUS_APPROVED])
            ->whereRaw('ABS(amount - ?) <= 0.01', [$amount])
            ->first();
        if (!$match) {
            return;
        }

        $match->update([
            'status'           => OutgoingPayment::STATUS_PAID,
            'paid_at'          => now(),
            'paid_transfer_id' => $bt->id,
            'match_method'     => 'op_id',
        ]);

        if ($match->transfer_id) {
            BankTransfer::where('transfer_id', $match->transfer_id)
                ->update(['comment' => DB::raw("CONCAT(COALESCE(comment, ''), ' | Vráceno – platba potvrzena')")]);
            $this->ledger->assignToOrganization((int) $match->transfer_id);
        }
    }
}
