<?php

namespace App\Domains\Audit\Repositories;

use App\Models\LogQueue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Fronta systémových záznamů k vyřízení (log_queues): chyby, upozornění, výsledky automatik. */
class LogQueueRepository
{
    public function paginate(string $type, string $state, int $perPage): LengthAwarePaginator
    {
        return DB::table('log_queues as lq')
            ->leftJoin('users as u', 'u.id', '=', 'lq.closed_by_user_id')
            ->select('lq.*', DB::raw("CONCAT(u.name, ' ', u.surname) AS closed_by_name"))
            ->when($type !== '', fn ($q) => $q->where('lq.type', (int) $type))
            ->when($state !== '', fn ($q) => $q->where('lq.state', (int) $state))
            ->orderBy('lq.id', 'desc')
            ->paginate($perPage)->withQueryString();
    }

    public function find(int $id): ?LogQueue
    {
        return LogQueue::find($id);
    }

    public function closedBy(?int $userId): ?object
    {
        return $userId ? DB::table('users')->where('id', $userId)->select('id', 'name', 'surname', 'login')->first() : null;
    }

    /** Komentáře k záznamu, nejstarší první. */
    public function comments(?int $threadId): Collection
    {
        if (!$threadId) {
            return collect();
        }
        return DB::table('comments as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.comments_thread_id', $threadId)
            ->select('c.*', DB::raw("COALESCE(CONCAT(u.name, ' ', u.surname), 'smazaný uživatel') AS user_name"), 'u.id AS uid')
            ->orderBy('c.datetime')
            ->get();
    }

    public function close(LogQueue $log, ?int $userId): void
    {
        $log->state             = LogQueue::STATE_CLOSED;
        $log->closed_at         = now();
        $log->closed_by_user_id = $userId;
        $log->save();
    }

    /** Uzavře všechny nové (volitelně jen daného typu). @return int počet */
    public function closeAllNew(string $type, ?int $userId): int
    {
        return DB::table('log_queues')->where('state', LogQueue::STATE_NEW)
            ->when($type !== '', fn ($q) => $q->where('type', (int) $type))
            ->update(['state' => LogQueue::STATE_CLOSED, 'closed_at' => now(), 'closed_by_user_id' => $userId]);
    }
}
