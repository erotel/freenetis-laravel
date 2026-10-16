<?php

namespace App\Domains\Billing\Listeners;

use App\Domains\Billing\Events\PaymentReceived;
use App\Services\InvoiceService;

/**
 * Doklad k platbě: účet služeb (payment_purpose=1) → faktura + PDF + e-mail,
 * účet příspěvků → potvrzení přijaté platby e-mailem.
 */
class IssuePaymentDocument
{
    public function handle(PaymentReceived $event): void
    {
        $invoices = new InvoiceService();
        if ((int) $event->bankAccount->payment_purpose === 1) {
            $invoices->generateServicesInvoice($event->memberId, $event->amount, $event->bankAccount->id, [$event->bankTransferId]);
        } else {
            $invoices->queuePaymentConfirmation($event->memberId, $event->amount, $event->bankAccount->name, [$event->bankTransferId]);
        }
    }
}
