<?php

namespace App\Domains\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Proběhla automatická měsíční srážka a strhla aspoň jeden poplatek. */
class FeesDeducted
{
    use Dispatchable;

    public function __construct(
        public readonly string $date,
        public readonly int $deductions,
    ) {
    }
}
