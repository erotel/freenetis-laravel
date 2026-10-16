<?php

namespace App\Services;

use App\Domains\Notifications\Actions\SyncPendingCustomerRedirectAction;

/** Fasáda → App\Domains\Notifications\Actions\SyncPendingCustomerRedirectAction. */
class PendingCustomerRedirectService
{
    /** @return bool true = něco zapsáno / smazáno */
    public function refreshForMember(int $memberId): bool
    {
        return app(SyncPendingCustomerRedirectAction::class)->handle($memberId);
    }
}
