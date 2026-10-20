<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Notifications\Repositories\RedirectionRepository;

/** Ruční přesměrování jedné IP na zprávu (existující přepíše autorem, komentářem, časem). */
class ActivateIpRedirectAction
{
    public function __construct(private RedirectionRepository $redirects)
    {
    }

    public function handle(int $ipId, int $messageId, ?int $userId, ?string $comment): void
    {
        $this->redirects->replace($messageId, collect([$ipId]), $userId, $comment, (string) now());
        AuditLogger::log('created', 'messages_ip_addresses', $ipId, null, ['message_id' => $messageId, 'scope' => 'ip']);
    }
}
