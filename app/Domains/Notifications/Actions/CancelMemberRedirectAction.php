<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Repositories\RedirectionRepository;
use App\Services\AuditLogger;

/** Zruší přesměrování na zprávu pro všechny IP člena (přímé i přes zařízení). */
class CancelMemberRedirectAction
{
    public function __construct(private RedirectionRepository $redirects)
    {
    }

    public function handle(int $memberId, int $messageId): void
    {
        $ipIds = $this->redirects->memberAllIpIds($memberId);
        $this->redirects->remove($messageId, $ipIds);
        AuditLogger::log('deleted', 'messages_ip_addresses', $memberId, [
            'message_id' => $messageId,
            'ip_count'   => $ipIds->count(),
            'scope'      => 'member',
        ], null);
    }
}
