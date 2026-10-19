<?php

namespace App\Http\Controllers;

use App\Domains\Audit\Repositories\LogQueueRepository;
use App\Models\LogQueue;
use Illuminate\Http\Request;

/** Fronta systémových záznamů k vyřízení; data v doméně Audit. */
class LogQueueController extends Controller
{
    public function __construct(private LogQueueRepository $logs) {}

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

        $logs = $this->logs->paginate((string) $filterType, (string) $filterState, 50);

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

        $log = $this->logs->find($id);
        abort_if(!$log, 404);
        $closedByUser = $this->logs->closedBy($log->closed_by_user_id);
        $comments     = $this->logs->comments($log->comments_thread_id);

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

        $log = $this->logs->find($id);
        abort_if(!$log, 404);

        if ($log->state === LogQueue::STATE_CLOSED) {
            return redirect()->route('log_queues.show', $id)
                ->with('error', 'Záznam je již uzavřen.');
        }

        $this->logs->close($log, auth()->id());

        return redirect()->route('log_queues.show', $id)
            ->with('success', 'Záznam byl uzavřen.');
    }

    // ── Close all (bulk, respects current filter) ─────────────────────────────

    public function closeAll(Request $request)
    {
        abort_unless($this->canEdit(), 403);

        $filterType  = $request->input('type', '');
        $filterState = $request->input('state', '');

        $count = $this->logs->closeAllNew((string) $filterType, auth()->id());

        $params = array_filter(['type' => $filterType, 'state' => $filterState], fn($v) => $v !== '');

        return redirect()->route('log_queues.index', $params)
            ->with('success', "Uzavřeno {$count} záznamů.");
    }
}
