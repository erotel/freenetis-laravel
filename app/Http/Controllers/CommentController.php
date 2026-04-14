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
     * Resolve member_id from a thread (only 'account' type supported for now).
     */
    private function getMemberIdFromThread(object $thread): int
    {
        if ($thread->type === 'account') {
            $account = DB::table('accounts')
                ->where('comments_thread_id', $thread->id)
                ->first();
            return $account?->member_id ?? 0;
        }
        return 0;
    }

    // ── Lazy thread creation + redirect to add ────────────────────────────────

    /**
     * Create comment thread for an account if not exists, then redirect to add form.
     * GET /comments/add-thread/account/{accountId}
     */
    public function addThread(string $type, int $fkId)
    {
        abort_unless($this->canAdd(), 403);
        abort_unless($type === 'account', 404);

        $account = DB::table('accounts')->where('id', $fkId)->first();
        abort_if(!$account, 404);

        if ($account->comments_thread_id) {
            $threadId = $account->comments_thread_id;
        } else {
            $threadId = DB::table('comments_threads')->insertGetId(['type' => $type]);
            DB::table('accounts')
                ->where('id', $fkId)
                ->update(['comments_thread_id' => $threadId]);
        }

        return redirect()->route('comments.add', $threadId);
    }

    // ── Add comment ───────────────────────────────────────────────────────────

    public function add(int $threadId)
    {
        abort_unless($this->canAdd(), 403);
        $thread   = $this->getThread($threadId);
        $memberId = $this->getMemberIdFromThread($thread);

        return view('comments.form', [
            'action'   => 'create',
            'thread'   => $thread,
            'memberId' => $memberId,
            'comment'  => null,
        ]);
    }

    public function store(Request $request, int $threadId)
    {
        abort_unless($this->canAdd(), 403);
        $thread   = $this->getThread($threadId);
        $memberId = $this->getMemberIdFromThread($thread);

        $request->validate(['text' => 'required|string|max:5000']);

        DB::table('comments')->insert([
            'comments_thread_id' => $threadId,
            'user_id'            => auth()->id(),
            'text'               => trim($request->input('text')),
            'datetime'           => now(),
        ]);

        return redirect()->route('members.show', $memberId)
            ->with('success', 'Komentář byl přidán.');
    }

    // ── Edit comment ──────────────────────────────────────────────────────────

    public function edit(int $id)
    {
        abort_unless($this->canEdit(), 403);
        $comment  = $this->getComment($id);
        $thread   = $this->getThread($comment->comments_thread_id);
        $memberId = $this->getMemberIdFromThread($thread);

        return view('comments.form', [
            'action'   => 'edit',
            'thread'   => $thread,
            'memberId' => $memberId,
            'comment'  => $comment,
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->canEdit(), 403);
        $comment  = $this->getComment($id);
        $thread   = $this->getThread($comment->comments_thread_id);
        $memberId = $this->getMemberIdFromThread($thread);

        $request->validate(['text' => 'required|string|max:5000']);

        DB::table('comments')->where('id', $id)->update([
            'text' => trim($request->input('text')),
        ]);

        return redirect()->route('members.show', $memberId)
            ->with('success', 'Komentář byl upraven.');
    }

    // ── Delete comment ────────────────────────────────────────────────────────

    public function destroy(int $id)
    {
        abort_unless($this->canDelete(), 403);
        $comment  = $this->getComment($id);
        $thread   = $this->getThread($comment->comments_thread_id);
        $memberId = $this->getMemberIdFromThread($thread);

        DB::table('comments')->where('id', $id)->delete();

        return redirect()->route('members.show', $memberId)
            ->with('success', 'Komentář byl smazán.');
    }
}
