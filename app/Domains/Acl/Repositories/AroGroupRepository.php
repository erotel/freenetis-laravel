<?php

namespace App\Domains\Acl\Repositories;

use App\Models\AroGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ACL skupiny (aro_groups, strom přes parent_id — lft/rgt jsou legacy a
 * nepoužívají se), členství uživatelů (groups_aro_map) a přiřazení pravidel
 * skupinám (aro_groups_map).
 */
class AroGroupRepository
{
    /** Všechny skupiny podle lft (s rodičem). */
    public function all(): Collection
    {
        return AroGroup::with('parent')->orderBy('lft')->get();
    }

    /** Skupiny pro výběr rodiče / v pravidle, volitelně bez jedné. */
    public function forSelect(?int $exceptId = null, array $columns = ['*']): Collection
    {
        return AroGroup::when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))->orderBy('lft')->get($columns);
    }

    public function find(int $id): ?AroGroup
    {
        return AroGroup::find($id);
    }

    public function findOrFail(int $id): AroGroup
    {
        return AroGroup::findOrFail($id);
    }

    public function findWithFamilyOrFail(int $id): AroGroup
    {
        return AroGroup::with(['parent', 'children'])->findOrFail($id);
    }

    public function create(string $name, int $parentId): AroGroup
    {
        return AroGroup::create([
            'name'      => $name,
            'parent_id' => $parentId,
            'lft'       => 0,
            'rgt'       => 0,
            'value'     => strtolower(str_replace(' ', '_', $name)),
        ]);
    }

    public function update(AroGroup $group, string $name, int $parentId): void
    {
        $group->update(['name' => $name, 'parent_id' => $parentId]);
    }

    public function hasChildren(AroGroup $group): bool
    {
        return $group->children()->count() > 0;
    }

    public function hasUsers(int $groupId): bool
    {
        return DB::table('groups_aro_map')->where('group_id', $groupId)->count() > 0;
    }

    /** Smaže skupinu i přiřazení pravidel (pravidla samotná zůstávají). */
    public function delete(AroGroup $group): void
    {
        DB::table('aro_groups_map')->where('group_id', $group->id)->delete();
        $group->delete();
    }

    /** @return Collection<object{id, login}> */
    public function users(int $groupId): Collection
    {
        return DB::table('groups_aro_map as gam')
            ->join('users', 'users.id', '=', 'gam.aro_id')
            ->where('gam.group_id', $groupId)
            ->select('users.id', 'users.login')
            ->orderBy('users.login')
            ->get();
    }

    /** Uživatelé mimo daná id (k přidání), podle loginu. */
    public function usersExcept(array $ids): Collection
    {
        return DB::table('users')->whereNotIn('id', $ids)->select('id', 'login')->orderBy('login')->get();
    }

    /** @return bool false = už byl členem */
    public function addUser(int $groupId, int $userId): bool
    {
        if (DB::table('groups_aro_map')->where('group_id', $groupId)->where('aro_id', $userId)->exists()) {
            return false;
        }
        DB::table('groups_aro_map')->insert(['group_id' => $groupId, 'aro_id' => $userId]);
        return true;
    }

    public function removeUser(int $groupId, int $userId): void
    {
        DB::table('groups_aro_map')->where('group_id', $groupId)->where('aro_id', $userId)->delete();
    }

    /** @return bool false = už bylo přiřazené */
    public function assignRule(int $groupId, int $aclId): bool
    {
        if (DB::table('aro_groups_map')->where('group_id', $groupId)->where('acl_id', $aclId)->exists()) {
            return false;
        }
        DB::table('aro_groups_map')->insert(['group_id' => $groupId, 'acl_id' => $aclId]);
        return true;
    }

    public function unassignRule(int $groupId, int $aclId): void
    {
        DB::table('aro_groups_map')->where('group_id', $groupId)->where('acl_id', $aclId)->delete();
    }
}
