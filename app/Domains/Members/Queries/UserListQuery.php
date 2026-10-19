<?php

namespace App\Domains\Members\Queries;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Seznam uživatelů: hledání stejnými poli jako globální hledání (login,
 * jméno i přes mezeru, kontakt; víc slov = AND tokenů ve jméně), filtr člena.
 */
class UserListQuery
{
    public function paginate(string $search, mixed $memberId, string $sort, string $dir, int $perPage): LengthAwarePaginator
    {
        $query = User::with('member')->select('users.*');

        if ($search !== '') {
            $like   = '%' . $search . '%';
            $tokens = MemberSearchQuery::tokens($search);
            $query->where(function ($q) use ($like, $tokens) {
                $q->where('users.login', 'like', $like)
                  ->orWhere(DB::raw("CONCAT(users.name, ' ', users.surname)"), 'like', $like);
                $q->orWhereExists(function ($sub) use ($like) {
                    $sub->from('users_contacts as uc')
                        ->join('contacts as c', 'c.id', '=', 'uc.contact_id')
                        ->whereColumn('uc.user_id', 'users.id')
                        ->where('c.value', 'like', $like);
                });
                if (count($tokens) > 1) {
                    MemberSearchQuery::allTokens($q, $tokens, "CONCAT(users.name, ' ', users.surname)");
                }
            });
        }
        if ($memberId) {
            $query->where('users.member_id', (int) $memberId);
        }

        return $query->orderBy('users.' . $sort, $dir)->paginate($perPage)->withQueryString();
    }
}
