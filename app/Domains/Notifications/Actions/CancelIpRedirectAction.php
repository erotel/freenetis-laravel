<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Repositories\RedirectionRepository;
use App\Services\AuditLogger;

class CancelIpRedirectAction
{
    public function __construct(private RedirectionRepository $redirects)
    {
    }

    public function handle(int $ipId, int $messageId): void
    {
        $this->redirects->remove($messageId, [$ipId]);
        AuditLogger::log('deleted', 'messages_ip_addresses', $ipId, ['message_id' => $messageId, 'scope' => 'ip'], null);
    }
}
