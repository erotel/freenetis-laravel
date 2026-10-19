<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Repositories\CommentRepository;
use App\Services\AuditLogger;

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
