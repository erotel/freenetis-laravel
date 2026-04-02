<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AclService
{
    /** @var array<int, int[]> group_id => [group_id, ...all parents] */
    private array $groupHierarchy = [];

    /** @var array<int, string[]> user_id => ['aco_value#axo_section#axo_value', ...] */
    private array $userPermissions = [];

    /**
     * Check whether a user has access to a given object/action.
     *
     * @param int    $userId       FreenetIS user id
     * @param string $acoValue     e.g. 'view_all' or 'view_own'
     * @param string $axoSection   e.g. 'Users_Controller'
     * @param string $axoValue     e.g. 'users'
     */
    public function hasAccess(int $userId, string $acoValue, string $axoSection, string $axoValue): bool
    {
        $permissions = $this->getPermissionsForUser($userId);
        $key = "{$acoValue}#{$axoSection}#{$axoValue}";

        return in_array($key, $permissions, true);
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

    // -------------------------------------------------------------------------

    private function getGroupHierarchy(): array
    {
        if ($this->groupHierarchy) {
            return $this->groupHierarchy;
        }

        // id => parent_id
        $groups = DB::table('aro_groups')->pluck('parent_id', 'id')->all();

        foreach ($groups as $id => $parentId) {
            $final = [$id];
            $stack = ($parentId == 0) ? [] : [$parentId];

            while ($top = array_pop($stack)) {
                if (isset($groups[$top]) && $groups[$top] != 0) {
                    $stack[] = $groups[$top];
                }
                $final[] = $top;
            }

            $this->groupHierarchy[$id] = array_unique($final);
        }

        return $this->groupHierarchy;
    }

    private function getPermissionsForUser(int $userId): array
    {
        if (isset($this->userPermissions[$userId])) {
            return $this->userPermissions[$userId];
        }

        $hierarchy = $this->getGroupHierarchy();

        // Groups the user directly belongs to
        $directGroups = DB::table('groups_aro_map')
            ->where('aro_id', $userId)
            ->pluck('group_id')
            ->all();

        if (empty($directGroups)) {
            return $this->userPermissions[$userId] = [];
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
        $rows = DB::table('aro_groups')
            ->join('aro_groups_map', 'aro_groups_map.group_id', '=', 'aro_groups.id')
            ->join('acl', 'acl.id', '=', 'aro_groups_map.acl_id')
            ->join('aco_map', 'aco_map.acl_id', '=', 'acl.id')
            ->join('aco', 'aco.value', '=', 'aco_map.value')
            ->join('axo_map', 'axo_map.acl_id', '=', 'acl.id')
            ->whereIn('aro_groups.id', $allGroups)
            ->select(DB::raw("CONCAT(aco.value, '#', axo_map.section_value, '#', axo_map.value) AS perm_key"))
            ->distinct()
            ->pluck('perm_key')
            ->all();

        return $this->userPermissions[$userId] = $rows;
    }
}
