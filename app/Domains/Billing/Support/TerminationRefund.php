<?php

namespace App\Domains\Billing\Support;

/** Vystavená vratka při ukončení členství. */
final class TerminationRefund
{
    public function __construct(
        public readonly int $outgoingPaymentId,
        public readonly string $docNumber,
        public readonly string $targetAccount,
        public readonly float $amount,
        public readonly ?string $pdfPath,   // null = PDF se nepodařilo vygenerovat
    ) {
    }
}
