<?php

namespace App\Services;

use App\Domains\Notifications\Actions\SyncDebtorRedirectAction;

/** Fasáda → App\Domains\Notifications\Actions\SyncDebtorRedirectAction. */
class PaymentBlockedRedirectService
{
    /** @return bool true = něco zapsáno / smazáno */
    public function refreshForMember(int $memberId): bool
    {
        return app(SyncDebtorRedirectAction::class)->handle($memberId);
    }
}
