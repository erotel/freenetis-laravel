<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Members\Repositories\CommentRepository;

class AddCommentAction
{
    public function __construct(private CommentRepository $comments)
    {
    }

    public function handle(int $threadId, string $text, ?int $userId): int
    {
        $text = trim($text);
        $id   = $this->comments->add($threadId, $userId, $text);
        AuditLogger::log('created', 'comments', $id, null, ['thread_id' => $threadId, 'text' => $text]);
        return $id;
    }
}
