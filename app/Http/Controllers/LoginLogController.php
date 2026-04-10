<?php

namespace App\Http\Controllers;

use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LoginLogController extends Controller
{
    public function index()
    {
        $users = DB::table('users')
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
            ->paginate(50);

        return view('login_logs.index', compact('users'));
    }

    public function showByUser(int $userId)
    {
        $authUser   = auth()->user();
        $targetUser = User::findOrFail($userId);

        if ($authUser->member_id === $targetUser->member_id) {
            $canView = $this->aclCheck('view_own', 'Login_logs_Controller', 'logs')
                    || $this->aclCheck('view_all', 'Login_logs_Controller', 'logs');
        } else {
            $canView = $this->aclCheck('view_all', 'Login_logs_Controller', 'logs');
        }

        abort_unless($canView, 403);

        $logs = LoginLog::where('user_id', $userId)
            ->orderBy('time', 'desc')
            ->paginate(50);

        return view('login_logs.show_by_user', compact('targetUser', 'logs'));
    }
}
