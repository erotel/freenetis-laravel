<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Bank\FioApiService;
use App\Domains\Billing\Documents\FioPaymentOrdersXml;
use App\Domains\Billing\Exceptions\OutgoingPaymentRejected;
use App\Domains\Billing\Repositories\OutgoingPaymentRepository;
use App\Models\BankAccount;
use App\Models\OutgoingPayment;
use Illuminate\Support\Facades\Cache;

/**
 * Export schválených odchozích plateb účtu: s FIO tokenem rovnou do FIO API
 * (odpověď se uloží k platbám), jinak XML ke stažení (jméno souboru k
 * platbám). Platby → exported; spárovaný bankovní převod dostane poznámku
 * „Čeká se na platbu z banky". Chyba FIO API = nic se nemění.
 *
 * Export účtu běží pod zámkem: dvojklik / druhá záložka by jinak stihla
 * načíst tytéž schválené platby dřív, než první export změní stav, a poslat
 * je do FIO podruhé (dvojí odeslání peněz).
 */
class ExportOutgoingPaymentsAction
{
    private const LOCK_SECONDS = 300;

    public function __construct(private OutgoingPaymentRepository $payments, private FioApiService $fio)
    {
    }

    /**
     * @return array{xml: string, filename: ?string, count: int} filename null = odesláno do FIO
     * @throws OutgoingPaymentRejected
     */
    public function handle(BankAccount $bankAccount): array
    {
        $lock = Cache::lock('outgoing-payments-export:' . $bankAccount->id, self::LOCK_SECONDS);
        if (!$lock->get()) {
            throw new OutgoingPaymentRejected('Export plateb z tohoto účtu právě probíhá. Počkejte chvíli a obnovte stránku.');
        }
        try {
            return $this->export($bankAccount);
        } finally {
            $lock->release();
        }
    }

    private function export(BankAccount $bankAccount): array
    {
        $payments = $this->payments->approvedFor((int) $bankAccount->id);
        if ($payments->isEmpty()) {
            throw new OutgoingPaymentRejected('Žádné schválené platby k exportu pro tento účet.');
        }

        $xml   = FioPaymentOrdersXml::build($payments, $bankAccount);
        $token = $this->payments->fioToken((int) $bankAccount->id);

        if ($token) {
            try {
                $response = $this->fio->importPayments($token, $xml);
            } catch (\RuntimeException $e) {
                throw new OutgoingPaymentRejected('Chyba FIO API: ' . $e->getMessage());
            }
            $this->markExported($payments, ['api_response' => $response]);
            return ['xml' => $xml, 'filename' => null, 'count' => $payments->count()];
        }

        $filename = 'platby_' . $bankAccount->account_nr . '_' . now()->format('Ymd_His') . '.xml';
        $this->markExported($payments, ['export_filename' => $filename]);
        return ['xml' => $xml, 'filename' => $filename, 'count' => $payments->count()];
    }

    private function markExported($payments, array $extra): void
    {
        $now = now();
        foreach ($payments as $payment) {
            $this->payments->update($payment, ['status' => OutgoingPayment::STATUS_EXPORTED, 'exported_at' => $now] + $extra);
        }
        foreach ($payments as $payment) {
            if ($payment->transfer_id) {
                $this->payments->noteAwaitingBankPayment((int) $payment->transfer_id);
            }
        }
    }
}
