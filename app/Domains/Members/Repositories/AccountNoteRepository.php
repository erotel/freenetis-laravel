<?php

namespace App\Domains\Members\Repositories;

use App\Models\AccountAttribute;
use Illuminate\Support\Facades\DB;

/** Poznámky (komentáře) k vláknu kreditního účtu člena. */
class AccountNoteRepository
{
    /** @return ?object {id, comments_thread_id} kreditního účtu */
    public function creditAccount(int $memberId): ?object
    {
        return DB::table('accounts')->where('member_id', $memberId)
            ->where('account_attribute_id', AccountAttribute::CREDIT_ATTRIBUTE_ID)
            ->orderBy('id')->first(['id', 'comments_thread_id']);
    }

    /** Vlákno účtu, založí ho líně (jako CommentController::addThread). */
    public function threadFor(object $account): int
    {
        if ($account->comments_thread_id) {
            return (int) $account->comments_thread_id;
        }
        $threadId = (int) DB::table('comments_threads')->insertGetId(['type' => 'account']);
        DB::table('accounts')->where('id', $account->id)->update(['comments_thread_id' => $threadId]);
        return $threadId;
    }

    public function addComment(int $threadId, ?int $userId, string $text): int
    {
        return (int) DB::table('comments')->insertGetId([
            'comments_thread_id' => $threadId,
            'user_id'            => $userId,
            'text'               => $text,
            'datetime'           => now(),
        ]);
    }
}
