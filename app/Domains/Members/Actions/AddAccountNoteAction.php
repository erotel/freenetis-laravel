<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Repositories\CommentRepository;
use App\Services\AuditLogger;

/** Poznámka technika ke kreditnímu účtu člena (Field Mode) + audit. */
class AddAccountNoteAction
{
    public function __construct(private CommentRepository $comments)
    {
    }

    /** @return ?int id komentáře, null = člen nemá kreditní účet */
    public function handle(int $memberId, string $text, ?int $userId, string $via): ?int
    {
        $accountId = $this->comments->creditAccountId($memberId);
        if (!$accountId) {
            return null;
        }

        $text      = trim($text);
        $threadId  = $this->comments->ensureThread('account', $accountId);
        $commentId = $this->comments->add($threadId, $userId, $text);

        AuditLogger::log('created', 'comments', $commentId, null, [
            'thread_id' => $threadId,
            'member_id' => $memberId,
            'text'      => $text,
            'via'       => $via,
        ]);

        return $commentId;
    }
}
