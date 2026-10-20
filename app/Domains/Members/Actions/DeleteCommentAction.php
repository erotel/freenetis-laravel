<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Members\Repositories\CommentRepository;

class DeleteCommentAction
{
    public function __construct(private CommentRepository $comments)
    {
    }

    public function handle(object $comment): void
    {
        $this->comments->delete((int) $comment->id);
        AuditLogger::log('deleted', 'comments', (int) $comment->id, ['thread_id' => $comment->comments_thread_id, 'text' => $comment->text], null);
    }
}
