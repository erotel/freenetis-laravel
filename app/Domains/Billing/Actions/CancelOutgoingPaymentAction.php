<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Exceptions\OutgoingPaymentRejected;
use App\Domains\Billing\Repositories\OutgoingPaymentRepository;
use App\Models\OutgoingPayment;

/** Zruší odchozí platbu — jen dokud není exportovaná (koncept / schválená). */
class CancelOutgoingPaymentAction
{
    public function __construct(private OutgoingPaymentRepository $payments)
    {
    }

    /** @throws OutgoingPaymentRejected */
    public function handle(OutgoingPayment $payment): void
    {
        if (!in_array($payment->status, [OutgoingPayment::STATUS_DRAFT, OutgoingPayment::STATUS_APPROVED], true)) {
            throw new OutgoingPaymentRejected('Platbu nelze zrušit v aktuálním stavu.');
        }
        $this->payments->update($payment, ['status' => OutgoingPayment::STATUS_CANCELLED]);
    }
}
