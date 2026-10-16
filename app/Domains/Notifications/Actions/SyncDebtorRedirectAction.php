<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Notifications\Repositories\RedirectionRepository;
use App\Domains\Notifications\Support\AutoMessages;
use App\Models\Setting;

/**
 * Přesměrování neplatiče podle payment_blocked: blokovaný → IP na dlužnickou
 * zprávu dle typu (zákazník 5 / člen 25), jinak smazat z OBOU (kdyby se mezitím
 * změnil typ). Volá se po platbě (dohnání) a z cronu members:redirect-blocked.
 * Idempotentní. @see AutoMessages::debtorTypeFor
 */
class SyncDebtorRedirectAction
{
    public function __construct(
        private RedirectionRepository $redirects,
        private MemberRepository $members,
    ) {
    }

    /** @return bool true = něco zapsáno / smazáno */
    public function handle(int $memberId): bool
    {
        if (!Setting::get('payment_blocked_redirect_enabled', 1)) {
            return false;
        }
        $member = $this->members->row($memberId);
        $type   = $member ? AutoMessages::debtorTypeFor((int) $member->type) : null;
        $msgId  = $type ? AutoMessages::idOf($type) : null;
        if (!$msgId) {
            return false; // bývalý / čekatel / zpráva chybí
        }
        $ipIds = $this->redirects->memberIpIds($memberId);
        if ($ipIds->isEmpty()) {
            return false;
        }

        if ((int) $member->payment_blocked === 1) {
            $this->redirects->addAuto($msgId, $ipIds, auth()->id() ?: 1, 'Auto: nedostatečná výše konta (prepaid)');
            return true;
        }
        return $this->redirects->removeFrom(AutoMessages::debtorIds(), $ipIds) > 0;
    }
}
