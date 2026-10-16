<?php

namespace App\Http\Controllers;

use App\Domains\Notifications\Actions\SendTemplateToMemberAction;
use App\Services\AclService;

abstract class Controller
{
    protected function aclCheck(string $action, string $section, string $value): bool
    {
        return app(AclService::class)->hasAccess(auth()->id() ?? 0, $action, $section, $value);
    }

    /**
     * Send a message template email to all email contacts of a member.
     * Substitutes {key} placeholders from $vars array.
     */
    protected function sendMessageToMember(int $messageId, int $memberId, array $vars = []): void
    {
        app(SendTemplateToMemberAction::class)->handle($messageId, $memberId, $vars);
    }
}
