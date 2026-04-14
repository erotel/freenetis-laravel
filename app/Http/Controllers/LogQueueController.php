<?php

namespace App\Http\Controllers;

use App\Models\LogQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogQueueController extends Controller
{
    // ACL: Log_queues_Controller / log_queue
    private function canView(): bool
    {
        return $this->aclCheck('view_all', 'Log_queues_Controller', 'log_queue');
    }

    private function canEdit(): bool
    {
        return $this->aclCheck('edit_all', 'Log_queues_Controller', 'log_queue');
    }

    private function canAddComment(): bool
    {
        return $this->aclCheck('new_all', 'Log_queues_Controller', 'comments');
    }

    private function canEditComment(): bool
    {
        return $this->aclCheck('edit_all', 'Log_queues_Controller', 'comments');
    }

    private function canDeleteComment(): bool
    {
        return $this->aclCheck('delete_all', 'Log_queues_Controller', 'comments');
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        abort_unless($this->canView(), 403);

        $filterType  = $request->input('type', '');
        $filterState = $request->input('state', '');

        $query = DB::table('log_queues as lq')
            ->leftJoin('users as u', 'u.id', '=', 'lq.closed_by_user_id')
            ->select('lq.*', DB::raw("CONCAT(u.name, ' ', u.surname) AS closed_by_name"));

        if ($filterType !== '') {
            $query->where('lq.type', (int) $filterType);
        }
        if ($filterState !== '') {
            $query->where('lq.state', (int) $filterState);
        }

        $logs = $query->orderBy('lq.id', 'desc')->paginate(50)->withQueryString();

        return view('log_queues.index', [
            'logs'        => $logs,
            'typeNames'   => LogQueue::typeNames(),
            'typeColors'  => LogQueue::typeColors(),
            'stateNames'  => LogQueue::stateNames(),
            'filterType'  => $filterType,
            'filterState' => $filterState,
            'canEdit'     => $this->canEdit(),
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(int $id)
    {
        abort_unless($this->canView(), 403);

        $log = LogQueue::find($id);
        abort_if(!$log, 404);

        $closedByUser = null;
        if ($log->closed_by_user_id) {
            $closedByUser = DB::table('users')
                ->where('id', $log->closed_by_user_id)
                ->select('id', 'name', 'surname', 'login')
                ->first();
        }

        $comments = collect();
        if ($log->comments_thread_id) {
            $comments = DB::table('comments as c')
                ->join('users as u', 'u.id', '=', 'c.user_id')
                ->where('c.comments_thread_id', $log->comments_thread_id)
                ->select('c.*', DB::raw("CONCAT(u.name, ' ', u.surname) AS user_name"), 'u.id AS uid')
                ->orderBy('c.datetime')
                ->get();
        }

        return view('log_queues.show', [
            'log'              => $log,
            'closedByUser'     => $closedByUser,
            'comments'         => $comments,
            'canEdit'          => $this->canEdit(),
            'canAddComment'    => $this->canAddComment(),
            'canEditComment'   => $this->canEditComment(),
            'canDeleteComment' => $this->canDeleteComment(),
        ]);
    }

    // ── Close single ─────────────────────────────────────────────────────────

    public function close(int $id)
    {
        abort_unless($this->canEdit(), 403);

        $log = LogQueue::find($id);
        abort_if(!$log, 404);

        if ($log->state === LogQueue::STATE_CLOSED) {
            return redirect()->route('log_queues.show', $id)
                ->with('error', 'Záznam je již uzavřen.');
        }

        $log->state             = LogQueue::STATE_CLOSED;
        $log->closed_at         = now();
        $log->closed_by_user_id = auth()->id();
        $log->save();

        return redirect()->route('log_queues.show', $id)
            ->with('success', 'Záznam byl uzavřen.');
    }

    // ── Close all (bulk, respects current filter) ─────────────────────────────

    public function closeAll(Request $request)
    {
        abort_unless($this->canEdit(), 403);

        $filterType  = $request->input('type', '');
        $filterState = $request->input('state', '');

        $query = DB::table('log_queues')->where('state', LogQueue::STATE_NEW);
        if ($filterType !== '') {
            $query->where('type', (int) $filterType);
        }

        $count = $query->update([
            'state'             => LogQueue::STATE_CLOSED,
            'closed_at'         => now(),
            'closed_by_user_id' => auth()->id(),
        ]);

        $params = array_filter(['type' => $filterType, 'state' => $filterState], fn($v) => $v !== '');

        return redirect()->route('log_queues.index', $params)
            ->with('success', "Uzavřeno {$count} záznamů.");
    }
}
