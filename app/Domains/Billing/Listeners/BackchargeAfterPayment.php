<?php

namespace App\Domains\Billing\Listeners;

use App\Domains\Billing\Events\PaymentReceived;
use App\Domains\Billing\Actions\ChargeOverdueFeesAction;
use App\Services\PaymentBlockedRedirectService;
use Illuminate\Support\Facades\Log;

/**
 * Prepaid dohnání: strhne měsíční poplatky, které srážka přeskočila
 * (payment_blocked=1); když kredit stačí, odblokuje a zruší přesměrování.
 * Chyba jen do logu — bankovní převod už je zapsaný.
 */
class BackchargeAfterPayment
{
    public function handle(PaymentReceived $event): void
    {
        try {
            if (app(ChargeOverdueFeesAction::class)->handle($event->memberId, date('Y-m-d'))) {
                app(PaymentBlockedRedirectService::class)->refreshForMember($event->memberId);
            }
        } catch (\Throwable $e) {
            Log::error(
                sprintf('PaymentBackcharge failed for member #%d (bank_transfer #%d): %s', $event->memberId, $event->bankTransferId, $e->getMessage()),
                ['trace' => $e->getTraceAsString()]
            );
        }
    }
}
