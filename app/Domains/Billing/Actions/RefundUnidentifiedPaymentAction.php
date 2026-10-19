<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Exceptions\OutgoingPaymentRejected;
use App\Domains\Billing\Repositories\OutgoingPaymentRepository;
use App\Models\BankTransfer;
use App\Models\OutgoingPayment;
use App\Services\AuditLogger;

/**
 * Vrácení neidentifikované platby jako koncept odchozí platby.
 *
 * Smí jít VÝHRADNĚ zpět odesílateli a NEJVÝŠ v přijaté částce. Cílový účet
 * i jméno proto odvozujeme ze serveru (z origin účtu přijaté platby), NE
 * z requestu — jinak by šlo poslat libovolnou částku na libovolný účet.
 */
class RefundUnidentifiedPaymentAction
{
    public function __construct(private OutgoingPaymentRepository $payments)
    {
    }

    /**
     * @param array{bank_account_id:int, amount:numeric-string|float, currency:string, variable_symbol?:?string, message?:?string} $data
     * @throws OutgoingPaymentRejected
     */
    public function handle(BankTransfer $bt, array $data, int $userId): OutgoingPayment
    {
        if (!$bt->transfer) {
            throw new OutgoingPaymentRejected('K tomuto bankovnímu pohybu chybí účetní převod — vrácení nelze provést.');
        }
        $origin = $bt->originAccount;
        if (!$origin || !$origin->account_nr) {
            throw new OutgoingPaymentRejected('Neznámý odesílatel platby — vrácení nelze bezpečně provést (chybí protiúčet).');
        }

        $maxAmount = round(abs((float) $bt->transfer->amount), 2);
        if (round((float) $data['amount'], 2) > $maxAmount) {
            throw new OutgoingPaymentRejected('Vrácená částka nesmí převýšit přijatou platbu (' . number_format($maxAmount, 2, ',', ' ') . ' Kč).');
        }

        $targetAccount = $origin->account_nr . '/' . $origin->bank_nr;

        if ($existing = $this->payments->forTransfer((int) $bt->transfer_id)) {
            throw new OutgoingPaymentRejected('Pro tento převod již existuje odchozí platba (stav: ' . $existing->status . ').');
        }

        $payment = $this->payments->create([
            'bank_account_id' => $data['bank_account_id'],
            'transfer_id'     => $bt->transfer_id,
            'target_account'  => $targetAccount,
            'target_name'     => $origin->name ?? '',
            'amount'          => $data['amount'],
            'currency'        => $data['currency'],
            'variable_symbol' => $data['variable_symbol'] ?? '',
            'message'         => $data['message'] ?? '',
            'reason'          => 'unidentified_refund',
            'status'          => 'draft',
            'created_by'      => $userId,
        ]);

        AuditLogger::log('created', 'outgoing_payments', (int) $bt->transfer_id, null, [
            'reason'         => 'unidentified_refund',
            'amount'         => $data['amount'],
            'target_account' => $targetAccount,
        ]);

        return $payment;
    }
}
