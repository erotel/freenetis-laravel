<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommentController extends Controller
{
    // ACL: Members_Controller / comment
    private function canView(): bool
    {
        return $this->aclCheck('view_all', 'Members_Controller', 'comment');
    }

    private function canAdd(): bool
    {
        return $this->aclCheck('new_all', 'Members_Controller', 'comment');
    }

    private function canEdit(): bool
    {
        return $this->aclCheck('edit_all', 'Members_Controller', 'comment');
    }

    private function canDelete(): bool
    {
        return $this->aclCheck('delete_all', 'Members_Controller', 'comment');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function getThread(int $threadId): object
    {
        $thread = DB::table('comments_threads')->where('id', $threadId)->first();
        abort_if(!$thread, 404);
        return $thread;
    }

    private function getComment(int $id): object
    {
        $comment = DB::table('comments')->where('id', $id)->first();
        abort_if(!$comment, 404);
        return $comment;
    }

    /**
     * Resolve the back-redirect after a comment action based on thread type.
     */
    private function getReturnRedirect(object $thread, string $message): \Illuminate\Http\RedirectResponse
    {
        if ($thread->type === 'account') {
            $account = DB::table('accounts')
                ->where('comments_thread_id', $thread->id)
                ->first();
            $memberId = $account?->member_id ?? 0;
            return redirect()->route('members.show', $memberId)->with('success', $message);
        }

        if ($thread->type === 'log_queue') {
            $logQueue = DB::table('log_queues')
                ->where('comments_thread_id', $thread->id)
                ->first();
            if ($logQueue) {
                return redirect()->route('log_queues.show', $logQueue->id)->with('success', $message);
            }
        }

        return redirect()->back()->with('success', $message);
    }

    // ── Lazy thread creation + redirect to add ────────────────────────────────

    /**
     * Create comment thread for an entity if not exists, then redirect to add form.
     * GET /comments/add-thread/{type}/{fkId}
     * Supported types: account, log_queue
     */
    public function addThread(string $type, int $fkId)
    {
        abort_unless($this->canAdd(), 403);
        abort_unless(in_array($type, ['account', 'log_queue']), 404);

        if ($type === 'account') {
            $record = DB::table('accounts')->where('id', $fkId)->first();
            abort_if(!$record, 404);

            $threadId = $record->comments_thread_id;
            if (!$threadId) {
                $threadId = DB::table('comments_threads')->insertGetId(['type' => $type]);
                DB::table('accounts')->where('id', $fkId)->update(['comments_thread_id' => $threadId]);
            }
        } elseif ($type === 'log_queue') {
            $record = DB::table('log_queues')->where('id', $fkId)->first();
            abort_if(!$record, 404);

            $threadId = $record->comments_thread_id;
            if (!$threadId) {
                $threadId = DB::table('comments_threads')->insertGetId(['type' => $type]);
                DB::table('log_queues')->where('id', $fkId)->update(['comments_thread_id' => $threadId]);
            }
        }

        return redirect()->route('comments.add', $threadId);
    }

    /**
     * Resolve a human-readable back URL for the comment form cancel link.
     */
    private function getBackUrl(object $thread): ?string
    {
        if ($thread->type === 'account') {
            $account = DB::table('accounts')->where('comments_thread_id', $thread->id)->first();
            $memberId = $account?->member_id;
            return $memberId ? route('members.show', $memberId) : null;
        }
        if ($thread->type === 'log_queue') {
            $lq = DB::table('log_queues')->where('comments_thread_id', $thread->id)->first();
            return $lq ? route('log_queues.show', $lq->id) : null;
        }
        return null;
    }

    // ── Add comment ───────────────────────────────────────────────────────────

    public function add(int $threadId)
    {
        abort_unless($this->canAdd(), 403);
        $thread  = $this->getThread($threadId);
        $backUrl = $this->getBackUrl($thread);

        return view('comments.form', [
            'action'  => 'create',
            'thread'  => $thread,
            'backUrl' => $backUrl,
            'comment' => null,
        ]);
    }

    public function store(Request $request, int $threadId)
    {
        abort_unless($this->canAdd(), 403);
        $thread = $this->getThread($threadId);

        $request->validate(['text' => 'required|string|max:5000']);

        $commentId = DB::table('comments')->insertGetId([
            'comments_thread_id' => $threadId,
            'user_id'            => auth()->id(),
            'text'               => trim($request->input('text')),
            'datetime'           => now(),
        ]);

        \App\Services\AuditLogger::log('created', 'comments', $commentId, null, [
            'thread_id' => $threadId,
            'text'      => trim($request->input('text')),
        ]);

        return $this->getReturnRedirect($thread, 'Komentář byl přidán.');
    }

    // ── Edit comment ──────────────────────────────────────────────────────────

    public function edit(int $id)
    {
        abort_unless($this->canEdit(), 403);
        $comment = $this->getComment($id);
        $thread  = $this->getThread($comment->comments_thread_id);
        $backUrl = $this->getBackUrl($thread);

        return view('comments.form', [
            'action'  => 'edit',
            'thread'  => $thread,
            'backUrl' => $backUrl,
            'comment' => $comment,
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->canEdit(), 403);
        $comment = $this->getComment($id);
        $thread  = $this->getThread($comment->comments_thread_id);

        $request->validate(['text' => 'required|string|max:5000']);

        DB::table('comments')->where('id', $id)->update([
            'text' => trim($request->input('text')),
        ]);

        \App\Services\AuditLogger::log('updated', 'comments', $id, [
            'text' => $comment->text,
        ], [
            'text' => trim($request->input('text')),
        ]);

        return $this->getReturnRedirect($thread, 'Komentář byl upraven.');
    }

    // ── Delete comment ────────────────────────────────────────────────────────

    public function destroy(int $id)
    {
        abort_unless($this->canDelete(), 403);
        $comment = $this->getComment($id);
        $thread  = $this->getThread($comment->comments_thread_id);

        DB::table('comments')->where('id', $id)->delete();

        \App\Services\AuditLogger::log('deleted', 'comments', $id, [
            'thread_id' => $comment->comments_thread_id,
            'text'      => $comment->text,
        ], null);

        return $this->getReturnRedirect($thread, 'Komentář byl smazán.');
    }
}
