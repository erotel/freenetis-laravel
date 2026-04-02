<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class FreenetisUserProvider extends EloquentUserProvider
{
    /**
     * Retrieve user by 'login' field (FreenetIS uses 'login', not 'email').
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        if (empty($credentials['login'])) {
            return null;
        }

        return $this->createModel()
            ->with('member')
            ->where('login', $credentials['login'])
            ->first();
    }

    /**
     * Validate password AND check that the member account is not locked.
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        if (!$this->hasher->check($credentials['password'], $user->getAuthPassword())) {
            return false;
        }

        // Block login if the member is locked
        if ($user->member && $user->member->locked) {
            return false;
        }

        return true;
    }
}
