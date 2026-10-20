<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Notifications\Repositories\RedirectionRepository;

/** Ruční přesměrování všech IP člena (přímých i přes zařízení) na zprávu. */
class ActivateMemberRedirectAction
{
    public function __construct(private RedirectionRepository $redirects)
    {
    }

    /** @return int počet IP (0 = člen nemá IP, nic se nestalo) */
    public function handle(int $memberId, int $messageId, ?int $userId, ?string $comment): int
    {
        $ipIds = $this->redirects->memberAllIpIds($memberId);
        if ($ipIds->isEmpty()) {
            return 0;
        }
        $this->redirects->replace($messageId, $ipIds->values(), $userId, $comment, (string) now());
        AuditLogger::log('created', 'messages_ip_addresses', $memberId, null, [
            'message_id' => $messageId,
            'ip_count'   => $ipIds->count(),
            'scope'      => 'member',
        ]);
        return $ipIds->count();
    }
}
