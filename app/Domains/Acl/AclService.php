<?php

namespace App\Domains\Acl;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AclService
{
    /** Cache TTL for compiled per-user permissions (seconds). */
    private const CACHE_TTL = 300;

    /** Cache key holding a counter that's bumped on global ACL changes. */
    private const CACHE_GEN_KEY = 'acl_cache_generation';

    /** @var array<int, int[]> group_id => [group_id, ...all parents] */
    private array $groupHierarchy = [];

    /** @var array<int, string[]> request-local cache: user_id => permissions */
    private array $userPermissions = [];

    /**
     * Check whether a user has access to a given object/action.
     */
    public function hasAccess(int $userId, string $acoValue, string $axoSection, string $axoValue): bool
    {
        $permissions = $this->getPermissionsForUser($userId);
        $key = "{$acoValue}#{$axoSection}#{$axoValue}";

        return in_array($key, $permissions, true);
    }

    /**
     * Smí $actorId spravovat účet $targetId (heslo, MFA, úprava, smazání)?
     * Jen když cíl nemá žádné právo, které by aktér neměl — jinak by technik
     * s edit_all na uživatele nastavil superadminovi heslo a převzal jeho
     * práva. Vlastní účet vždy (změna vlastního hesla hlídá aktuální heslo).
     */
    public function canManageUser(int $actorId, int $targetId): bool
    {
        if ($actorId === $targetId) {
            return true;
        }
        $actor = $this->getPermissionsForUser($actorId);
        foreach (array_diff($this->getPermissionsForUser($targetId), $actor) as $perm) {
            // právo k vlastním údajům (view_own…) pokrývá stejné právo ke všem (view_all…)
            if (!preg_match('/^(\w+)_own#(.*)$/', $perm, $m) || !in_array("{$m[1]}_all#{$m[2]}", $actor, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Replicate MY_Controller::acl_check():
     *   - if member_id matches the logged-in user's member_id, check own first
     *   - then check all
     */
    public function check(
        int $userId,
        int $userMemberId,
        string $acoType,
        string $axoSection,
        string $axoValue,
        ?int $memberIdParam = null,
        bool $forceOwn = false
    ): bool {
        if ($memberIdParam === $userMemberId || $forceOwn) {
            if ($this->hasAccess($userId, $acoType . '_own', $axoSection, $axoValue)) {
                return true;
            }
        }

        return $this->hasAccess($userId, $acoType . '_all', $axoSection, $axoValue);
    }

    /**
     * Invalidate the cached permissions for a single user.
     * Call this whenever the user's group membership changes.
     */
    public function flushUserCache(int $userId): void
    {
        Cache::forget($this->cacheKey($userId));
        unset($this->userPermissions[$userId]);
    }

    /**
     * Invalidate cached permissions for ALL users — use when ACL rules,
     * group hierarchy, or group ↔ ACL mappings change. Bumps a generation
     * counter so all existing keys become stale instantly without scanning.
     */
    public function flushAllCache(): void
    {
        Cache::increment(self::CACHE_GEN_KEY);
        $this->userPermissions = [];
        $this->groupHierarchy  = [];
    }

    // -------------------------------------------------------------------------

    private function cacheKey(int $userId): string
    {
        $gen = (int) Cache::rememberForever(self::CACHE_GEN_KEY, fn() => 1);
        return "acl_user_{$userId}_v{$gen}";
    }

    private function getGroupHierarchy(): array
    {
        if ($this->groupHierarchy) {
            return $this->groupHierarchy;
        }

        // id => parent_id
        $groups = DB::table('aro_groups')->pluck('parent_id', 'id')->all();

        // Skupina + všichni předci. $seen: cyklus v parent_id (chybná data)
        // nesmí výpočet zacyklit — jinak by visela každá kontrola práv.
        foreach ($groups as $id => $parentId) {
            $final = [$id];
            $seen  = [$id => true];
            $top   = (int) $parentId;

            while ($top !== 0 && !isset($seen[$top])) {
                $seen[$top] = true;
                $final[]    = $top;
                $top        = (int) ($groups[$top] ?? 0);
            }

            $this->groupHierarchy[$id] = $final;
        }

        return $this->groupHierarchy;
    }

    protected function getPermissionsForUser(int $userId): array
    {
        // 1) request-local cache (avoids re-hitting Cache 11× per page load from the menu)
        if (isset($this->userPermissions[$userId])) {
            return $this->userPermissions[$userId];
        }

        // 2) cross-request cache (Laravel Cache, default file driver)
        $perms = Cache::remember(
            $this->cacheKey($userId),
            self::CACHE_TTL,
            fn() => $this->loadPermissions($userId)
        );

        return $this->userPermissions[$userId] = $perms;
    }

    private function loadPermissions(int $userId): array
    {
        $hierarchy = $this->getGroupHierarchy();

        // Groups the user directly belongs to
        $directGroups = DB::table('groups_aro_map')
            ->where('aro_id', $userId)
            ->pluck('group_id')
            ->all();

        if (empty($directGroups)) {
            return [];
        }

        // Expand to include all ancestor groups
        $allGroups = [];
        foreach ($directGroups as $gid) {
            if (isset($hierarchy[$gid])) {
                $allGroups = array_merge($allGroups, $hierarchy[$gid]);
            }
        }
        $allGroups = array_unique($allGroups);

        // Fetch ACL rules for all resolved groups
        return DB::table('aro_groups')
            ->join('aro_groups_map', 'aro_groups_map.group_id', '=', 'aro_groups.id')
            ->join('acl',     'acl.id',          '=', 'aro_groups_map.acl_id')
            ->join('aco_map', 'aco_map.acl_id',  '=', 'acl.id')
            ->join('aco',     'aco.value',       '=', 'aco_map.value')
            ->join('axo_map', 'axo_map.acl_id',  '=', 'acl.id')
            ->whereIn('aro_groups.id', $allGroups)
            ->select(DB::raw("CONCAT(aco.value, '#', axo_map.section_value, '#', axo_map.value) AS perm_key"))
            ->distinct()
            ->pluck('perm_key')
            ->all();
    }
}
