<?php

namespace App\Domains\Access\Actions;

use App\Domains\Notifications\Actions\SendTemplateToMemberAction;
use App\Models\ConnectionRequest;

/** Zamítnutí čekající žádosti o připojení + zpráva 14 žadateli. Stav ověřuje volající. */
class RejectConnectionRequestAction
{
    private const MESSAGE_REJECTED = 14;

    public function __construct(private SendTemplateToMemberAction $sendTemplate)
    {
    }

    public function handle(ConnectionRequest $cr, ?int $decidedBy): void
    {
        $cr->update([
            'state'           => ConnectionRequest::STATE_REJECTED,
            'decided_user_id' => $decidedBy,
            'decided_at'      => now(),
        ]);

        $this->sendTemplate->handle(self::MESSAGE_REJECTED, $cr->member_id, [
            'member_name' => $cr->member?->name ?? '',
            'comment'     => $cr->comment ?? '',
        ]);
    }
}
