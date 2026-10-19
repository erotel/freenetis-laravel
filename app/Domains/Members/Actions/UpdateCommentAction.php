<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Repositories\CommentRepository;
use App\Services\AuditLogger;

class UpdateCommentAction
{
    public function __construct(private CommentRepository $comments)
    {
    }

    public function handle(object $comment, string $text): void
    {
        $text = trim($text);
        $this->comments->setText((int) $comment->id, $text);
        AuditLogger::log('updated', 'comments', (int) $comment->id, ['text' => $comment->text], ['text' => $text]);
    }
}
