<?php

namespace App\Domains\Billing\Events;

use App\Models\BankAccount;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Importovaná příchozí platba byla spárována na člena a připsána na jeho kredit
 * (dispatch po commitu importu, pro každou platbu zvlášť).
 */
class PaymentReceived
{
    use Dispatchable;

    public function __construct(
        public readonly int $memberId,
        public readonly float $amount,
        public readonly int $bankTransferId,
        public readonly BankAccount $bankAccount,
    ) {
    }
}
