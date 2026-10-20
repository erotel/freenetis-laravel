<?php

namespace App\Http\Controllers;

use App\Domains\Members\Actions\AddCommentAction;
use App\Domains\Members\Actions\DeleteCommentAction;
use App\Domains\Members\Actions\UpdateCommentAction;
use App\Domains\Members\Repositories\CommentRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Komentáře k účtu člena a k záznamům logu (ACL Members_Controller/comment). */
class CommentController extends Controller
{
    public function __construct(private CommentRepository $comments) {}

    private function can(string $action): bool
    {
        return $this->aclCheck($action, 'Members_Controller', 'comment');
    }

    private function threadOrFail(int $id): object
    {
        $thread = $this->comments->thread($id);
        abort_if(!$thread, 404);
        return $thread;
    }

    private function commentOrFail(int $id): object
    {
        $comment = $this->comments->comment($id);
        abort_if(!$comment, 404);
        return $comment;
    }

    /** Návrat na vlastníka vlákna: karta člena (účet), detail logu, jinak zpět. */
    private function returnTo(object $thread, string $message): RedirectResponse
    {
        if ($thread->type === 'account') {
            return redirect()->route('members.show', $this->comments->accountMemberId((int) $thread->id) ?? 0)->with('success', $message);
        }
        if ($thread->type === 'log_queue' && ($lq = $this->comments->logQueueId((int) $thread->id))) {
            return redirect()->route('log_queues.show', $lq)->with('success', $message);
        }
        return redirect()->back()->with('success', $message);
    }

    /** Odkaz „zpět" ve formuláři. */
    private function backUrl(object $thread): ?string
    {
        if ($thread->type === 'account') {
            $memberId = $this->comments->accountMemberId((int) $thread->id);
            return $memberId ? route('members.show', $memberId) : null;
        }
        if ($thread->type === 'log_queue') {
            $lq = $this->comments->logQueueId((int) $thread->id);
            return $lq ? route('log_queues.show', $lq) : null;
        }
        return null;
    }

    /**
     * Formulář prvního komentáře k účtu / záznamu logu. Nic nezapisuje — vlákno
     * vznikne až uložením (storeFirst); existuje-li, přejde na jeho formulář.
     */
    public function addThread(string $type, int $fkId)
    {
        abort_unless($this->can('new_all'), 403);
        abort_unless(isset(CommentRepository::OWNER_TABLES[$type]), 404);

        $owner = $this->comments->owner($type, $fkId);
        abort_if(!$owner, 404);
        if ($owner->comments_thread_id) {
            return redirect()->route('comments.add', $owner->comments_thread_id);
        }

        return view('comments.form', [
            'action'     => 'create',
            'thread'     => null,
            'comment'    => null,
            'formAction' => route('comments.store-first', ['type' => $type, 'fkId' => $fkId]),
            'backUrl'    => $type === 'account'
                ? ($owner->member_id ? route('members.show', $owner->member_id) : null)
                : route('log_queues.show', $fkId),
        ]);
    }

    /** Uloží první komentář — teprve teď založí vlákno (když mezitím nevzniklo). */
    public function storeFirst(Request $request, string $type, int $fkId, AddCommentAction $add)
    {
        abort_unless($this->can('new_all'), 403);
        abort_unless(isset(CommentRepository::OWNER_TABLES[$type]), 404);
        $request->validate(['text' => 'required|string|max:5000']);

        $threadId = $this->comments->ensureThread($type, $fkId);
        abort_if($threadId === null, 404);

        $add->handle($threadId, (string) $request->input('text'), auth()->id());
        return $this->returnTo($this->threadOrFail($threadId), 'Komentář byl přidán.');
    }

    public function add(int $threadId)
    {
        abort_unless($this->can('new_all'), 403);
        $thread = $this->threadOrFail($threadId);

        return view('comments.form', ['action' => 'create', 'thread' => $thread, 'backUrl' => $this->backUrl($thread), 'comment' => null,
            'formAction' => route('comments.store', $thread->id)]);
    }

    public function store(Request $request, int $threadId, AddCommentAction $add)
    {
        abort_unless($this->can('new_all'), 403);
        $thread = $this->threadOrFail($threadId);
        $request->validate(['text' => 'required|string|max:5000']);

        $add->handle($threadId, (string) $request->input('text'), auth()->id());
        return $this->returnTo($thread, 'Komentář byl přidán.');
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);
        $comment = $this->commentOrFail($id);
        $thread  = $this->threadOrFail((int) $comment->comments_thread_id);

        return view('comments.form', ['action' => 'edit', 'thread' => $thread, 'backUrl' => $this->backUrl($thread), 'comment' => $comment]);
    }

    public function update(Request $request, int $id, UpdateCommentAction $update)
    {
        abort_unless($this->can('edit_all'), 403);
        $comment = $this->commentOrFail($id);
        $thread  = $this->threadOrFail((int) $comment->comments_thread_id);
        $request->validate(['text' => 'required|string|max:5000']);

        $update->handle($comment, (string) $request->input('text'));
        return $this->returnTo($thread, 'Komentář byl upraven.');
    }

    public function destroy(int $id, DeleteCommentAction $delete)
    {
        abort_unless($this->can('delete_all'), 403);
        $comment = $this->commentOrFail($id);
        $thread  = $this->threadOrFail((int) $comment->comments_thread_id);

        $delete->handle($comment);
        return $this->returnTo($thread, 'Komentář byl smazán.');
    }
}
