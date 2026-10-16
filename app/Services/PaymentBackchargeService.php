<?php

namespace App\Services;

use App\Domains\Billing\Actions\ChargeOverdueFeesAction;

/**
 * Fasáda → App\Domains\Billing\Actions\ChargeOverdueFeesAction (prepaid dohnání
 * poplatků po přijaté platbě). Zůstává kvůli stávajícím volajícím.
 */
class PaymentBackchargeService
{
    /** @return bool true = blokace zrušena (payment_blocked 1 → 0) */
    public function backchargeForMember(int $memberId, string $today): bool
    {
        return app(ChargeOverdueFeesAction::class)->handle($memberId, $today);
    }
}
