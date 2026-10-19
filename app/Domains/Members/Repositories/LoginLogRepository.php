<?php

namespace App\Domains\Members\Repositories;

use App\Models\LoginLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Historie přihlášení (login_logs). */
class LoginLogRepository
{
    /** Uživatelé s posledním přihlášením (nejnovější první, „Nikdy" na konci). */
    public function usersByLastLogin(int $perPage): LengthAwarePaginator
    {
        return DB::table('users')
            ->leftJoin(
                DB::raw('(SELECT user_id, MAX(time) as last_time FROM login_logs GROUP BY user_id) as ll'),
                'users.id', '=', 'll.user_id'
            )
            ->select(
                'users.id',
                'users.login',
                'users.name',
                'users.surname',
                DB::raw("IFNULL(ll.last_time, 'Nikdy') as last_time")
            )
            ->orderByRaw("IF(ll.last_time IS NULL, 0, 1) DESC, ll.last_time DESC")
            ->paginate($perPage);
    }

    public function forUser(int $userId, int $perPage): LengthAwarePaginator
    {
        return LoginLog::where('user_id', $userId)->orderBy('time', 'desc')->paginate($perPage);
    }

    /** Záznam úspěšného přihlášení. */
    public function record(int $userId, ?string $ip): void
    {
        DB::table('login_logs')->insert(['user_id' => $userId, 'time' => now(), 'IP_address' => $ip]);
    }
}
