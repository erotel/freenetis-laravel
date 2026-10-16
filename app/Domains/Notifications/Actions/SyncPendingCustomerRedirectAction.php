<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Notifications\Repositories\RedirectionRepository;
use App\Domains\Notifications\Support\AutoMessages;
use App\Helpers\MemberType;
use App\Models\Message;
use App\Models\Setting;

/**
 * Přesměrování čekajícího zákazníka (18, nepodepsaná smlouva): typ 18 → IP na
 * zprávu 32, jinak smazat. Volá se po podpisu smlouvy, po přidání IP zařízení
 * a z cronu members:redirect-pending-customers. Idempotentní.
 */
class SyncPendingCustomerRedirectAction
{
    public function __construct(
        private RedirectionRepository $redirects,
        private MemberRepository $members,
    ) {
    }

    /** @return bool true = něco zapsáno / smazáno */
    public function handle(int $memberId): bool
    {
        if (!Setting::get('pending_customer_redirect_enabled', 1)) {
            return false;
        }
        $msgId = AutoMessages::idOf(Message::PENDING_CUSTOMER_MESSAGE);
        $member = $msgId ? $this->members->row($memberId) : null;
        if (!$member) {
            return false;
        }
        $ipIds = $this->redirects->memberIpIds($memberId);
        if ($ipIds->isEmpty()) {
            return false;
        }

        if ((int) $member->type === MemberType::PENDING_CUSTOMER) {
            $this->redirects->addAuto($msgId, $ipIds, auth()->id() ?: 1, 'Auto: čekající zákazník (nepodepsaná smlouva)');
            return true;
        }
        return $this->redirects->remove($msgId, $ipIds) > 0;
    }
}
