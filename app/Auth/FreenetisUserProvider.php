<?php

namespace App\Auth;

use App\Models\Setting;
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
     *
     * Supports legacy SHA1/MD5 hashes from the original Kohana FreeNetIS and
     * transparently rehashes them to bcrypt on successful login.
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $plain  = $credentials['password'] ?? '';
        $stored = $user->getAuthPassword();

        if ($plain === '' || $stored === '') {
            return false;
        }

        $verified = false;

        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$') || str_starts_with($stored, '$2b$')) {
            $verified = password_verify($plain, $stored);
        } elseif (strlen($stored) === 40 && ctype_xdigit($stored)) {
            $verified = hash_equals($stored, sha1($plain));
        } elseif (strlen($stored) === 32 && ctype_xdigit($stored) && Setting::get('pasword_check_for_md5', 0)) {
            $verified = hash_equals($stored, md5($plain));
        }

        if (!$verified) {
            return false;
        }

        if ($user->member && $user->member->locked) {
            return false;
        }

        if (!str_starts_with($stored, '$2')) {
            $user->forceFill(['password' => password_hash($plain, PASSWORD_BCRYPT)])->saveQuietly();
        }

        return true;
    }
}
