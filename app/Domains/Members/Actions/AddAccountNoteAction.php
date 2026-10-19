<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Repositories\AccountNoteRepository;
use App\Services\AuditLogger;

/** Poznámka technika ke kreditnímu účtu člena (Field Mode) + audit. */
class AddAccountNoteAction
{
    public function __construct(private AccountNoteRepository $notes)
    {
    }

    /** @return ?int id komentáře, null = člen nemá kreditní účet */
    public function handle(int $memberId, string $text, ?int $userId, string $via): ?int
    {
        $account = $this->notes->creditAccount($memberId);
        if (!$account) {
            return null;
        }

        $text      = trim($text);
        $threadId  = $this->notes->threadFor($account);
        $commentId = $this->notes->addComment($threadId, $userId, $text);

        AuditLogger::log('created', 'comments', $commentId, null, [
            'thread_id' => $threadId,
            'member_id' => $memberId,
            'text'      => $text,
            'via'       => $via,
        ]);

        return $commentId;
    }
}
