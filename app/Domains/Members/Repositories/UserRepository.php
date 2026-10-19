<?php

namespace App\Domains\Members\Repositories;

use App\Models\DeviceAdmin;
use App\Models\DeviceEngineer;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Uživatelé členů (hlavní = type 1, další = 2). */
class UserRepository
{
    public function find(int $id): ?User
    {
        return User::find($id);
    }

    public function findWithMember(int $id): ?User
    {
        return User::with('member')->find($id);
    }

    /** Nejstarší (hlavní) uživatel člena. */
    public function firstOfMember(int $memberId): ?User
    {
        return User::where('member_id', $memberId)->orderBy('id')->first();
    }

    public function findOrFail(int $id): User
    {
        return User::findOrFail($id);
    }

    public function member(int $id): ?Member
    {
        return Member::find($id);
    }

    public function membersByName(): Collection
    {
        return Member::orderBy('name')->get();
    }

    /** Skupiny oprávnění uživatele (id, název). */
    public function aclGroups(int $userId): Collection
    {
        return DB::table('groups_aro_map')
            ->join('aro_groups', 'aro_groups.id', '=', 'groups_aro_map.group_id')
            ->where('groups_aro_map.aro_id', $userId)
            ->select('aro_groups.id', 'aro_groups.name')
            ->get();
    }

    public function deviceAdmins(int $userId): Collection
    {
        return DeviceAdmin::where('user_id', $userId)->with('device')->get();
    }

    public function deviceEngineers(int $userId): Collection
    {
        return DeviceEngineer::where('user_id', $userId)->with('device')->get();
    }

    /** Nový uživatel: hash hesla, náhodné aplikační heslo (povinné), prázdné nastavení. */
    public function create(array $data): User
    {
        $data['password'] = Hash::make($data['password']);
        return User::create($data + [
            'application_password' => Crypt::encryptString(User::generateApplicationPassword()),
            'settings'             => '',
        ]);
    }

    public function update(User $user, array $data): void
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $user->update($data);
    }

    public function setPassword(User $user, string $password): void
    {
        $user->password = Hash::make($password);
        $user->save();
    }

    public function memberUserCount(?int $memberId): int
    {
        return User::where('member_id', $memberId)->count();
    }

    public function delete(User $user): void
    {
        $user->delete();
    }

    public function memberRow(?int $memberId): ?object
    {
        return DB::table('members')->where('id', $memberId)->first();
    }

    public function setDarkMode(User $user, bool $on): void
    {
        $settings = json_decode($user->settings ?? '{}', true) ?: [];
        $settings['dark_mode'] = $on ? 1 : 0;
        $user->settings = json_encode($settings);
        $user->save();
    }
}
