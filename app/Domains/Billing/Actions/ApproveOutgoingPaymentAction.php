<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Exceptions\OutgoingPaymentRejected;
use App\Domains\Billing\Repositories\OutgoingPaymentRepository;
use App\Models\OutgoingPayment;

/** Schválí koncept odchozí platby (pak jde exportovat). */
class ApproveOutgoingPaymentAction
{
    public function __construct(private OutgoingPaymentRepository $payments)
    {
    }

    /** @throws OutgoingPaymentRejected */
    public function handle(OutgoingPayment $payment, ?int $userId): void
    {
        if ($payment->status !== OutgoingPayment::STATUS_DRAFT) {
            throw new OutgoingPaymentRejected('Platbu lze schválit pouze ve stavu Koncept.');
        }
        $this->payments->update($payment, ['status' => OutgoingPayment::STATUS_APPROVED, 'approved_by' => $userId]);
    }
}
