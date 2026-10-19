<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Exceptions\UserNotDeletable;
use App\Domains\Members\Repositories\UserRepository;
use App\Models\User;

/** Smaže uživatele — ne sebe, ne se zařízeními, ne jediného uživatele člena. */
class RemoveUserAction
{
    public function __construct(private UserRepository $users)
    {
    }

    /** @throws UserNotDeletable */
    public function handle(User $user, ?int $actorId): void
    {
        if ($user->id === $actorId) {
            throw new UserNotDeletable('Nelze smazat vlastní účet.');
        }
        if ($user->devices()->exists()) {
            throw new UserNotDeletable('Uživatele nelze smazat, má přiřazená zařízení.');
        }
        if ($this->users->memberUserCount($user->member_id) <= 1) {
            throw new UserNotDeletable('Uživatele nelze smazat, je jediným uživatelem člena.');
        }
        $this->users->delete($user);
    }
}
