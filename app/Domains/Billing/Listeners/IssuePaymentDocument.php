<?php

namespace App\Domains\Billing\Listeners;

use App\Domains\Billing\Events\PaymentReceived;
use App\Domains\Billing\Actions\IssueServicesInvoiceAction;
use App\Domains\Billing\Actions\QueuePaymentConfirmationAction;

/**
 * Doklad k platbě: účet služeb (payment_purpose=1) → faktura + PDF + e-mail,
 * účet příspěvků → potvrzení přijaté platby e-mailem.
 */
class IssuePaymentDocument
{
    public function __construct(
        private IssueServicesInvoiceAction $invoice,
        private QueuePaymentConfirmationAction $confirmation,
    ) {
    }

    public function handle(PaymentReceived $event): void
    {
        if ((int) $event->bankAccount->payment_purpose === 1) {
            $this->invoice->handle($event->memberId, $event->amount, $event->bankAccount->id, [$event->bankTransferId]);
        } else {
            $this->confirmation->handle($event->memberId, $event->amount, $event->bankAccount->name, [$event->bankTransferId]);
        }
    }
}
