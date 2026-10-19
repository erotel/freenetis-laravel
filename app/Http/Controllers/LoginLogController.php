<?php

namespace App\Http\Controllers;

use App\Domains\Members\Repositories\LoginLogRepository;
use App\Domains\Members\Repositories\UserRepository;

class LoginLogController extends Controller
{
    public function __construct(private LoginLogRepository $logs)
    {
    }

    public function index()
    {
        return view('login_logs.index', ['users' => $this->logs->usersByLastLogin(50)]);
    }

    public function showByUser(int $userId, UserRepository $users)
    {
        $authUser   = auth()->user();
        $targetUser = $users->findOrFail($userId);

        if ($authUser->member_id === $targetUser->member_id) {
            $canView = $this->aclCheck('view_own', 'Login_logs_Controller', 'logs')
                    || $this->aclCheck('view_all', 'Login_logs_Controller', 'logs');
        } else {
            $canView = $this->aclCheck('view_all', 'Login_logs_Controller', 'logs');
        }

        abort_unless($canView, 403);

        return view('login_logs.show_by_user', ['targetUser' => $targetUser, 'logs' => $this->logs->forUser($userId, 50)]);
    }
}
