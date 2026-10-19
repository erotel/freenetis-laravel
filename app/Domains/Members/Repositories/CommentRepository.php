<?php

namespace App\Domains\Members\Repositories;

use App\Models\AccountAttribute;
use Illuminate\Support\Facades\DB;

/**
 * Komentáře ve vláknech (comments_threads) navázaných na kreditní účet člena
 * (accounts.comments_thread_id, typ „account") nebo na záznam logu
 * (log_queues.comments_thread_id, typ „log_queue"). Vlákno se zakládá líně.
 */
class CommentRepository
{
    /** typ vlákna => tabulka vlastníka */
    public const OWNER_TABLES = ['account' => 'accounts', 'log_queue' => 'log_queues'];

    public function thread(int $id): ?object
    {
        return DB::table('comments_threads')->where('id', $id)->first();
    }

    public function comment(int $id): ?object
    {
        return DB::table('comments')->where('id', $id)->first();
    }

    /** Id kreditního účtu člena, nebo null. */
    public function creditAccountId(int $memberId): ?int
    {
        $id = DB::table('accounts')->where('member_id', $memberId)
            ->where('account_attribute_id', AccountAttribute::CREDIT_ATTRIBUTE_ID)
            ->orderBy('id')->value('id');
        return $id ? (int) $id : null;
    }

    /** Vlákno vlastníka (založí ho, když chybí); null = vlastník neexistuje. */
    public function ensureThread(string $type, int $ownerId): ?int
    {
        $table  = self::OWNER_TABLES[$type];
        $record = DB::table($table)->where('id', $ownerId)->first();
        if (!$record) {
            return null;
        }
        if ($record->comments_thread_id) {
            return (int) $record->comments_thread_id;
        }
        $threadId = (int) DB::table('comments_threads')->insertGetId(['type' => $type]);
        DB::table($table)->where('id', $ownerId)->update(['comments_thread_id' => $threadId]);
        return $threadId;
    }

    /** Člen, jehož účtu vlákno patří (null = nenalezen). */
    public function accountMemberId(int $threadId): ?int
    {
        $id = DB::table('accounts')->where('comments_thread_id', $threadId)->value('member_id');
        return $id !== null ? (int) $id : null;
    }

    /** Záznam logu, jemuž vlákno patří. */
    public function logQueueId(int $threadId): ?int
    {
        $id = DB::table('log_queues')->where('comments_thread_id', $threadId)->value('id');
        return $id !== null ? (int) $id : null;
    }

    public function add(int $threadId, ?int $userId, string $text): int
    {
        return (int) DB::table('comments')->insertGetId([
            'comments_thread_id' => $threadId,
            'user_id'            => $userId,
            'text'               => $text,
            'datetime'           => now(),
        ]);
    }

    public function setText(int $id, string $text): void
    {
        DB::table('comments')->where('id', $id)->update(['text' => $text]);
    }

    public function delete(int $id): void
    {
        DB::table('comments')->where('id', $id)->delete();
    }
}
