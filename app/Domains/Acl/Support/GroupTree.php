<?php

namespace App\Domains\Acl\Support;

/** Strom skupin z plochého seznamu (parent_id): [{group, depth, children}]. */
final class GroupTree
{
    public static function build(iterable $groups, $parentId = null, int $depth = 0): array
    {
        $tree = [];
        foreach ($groups as $group) {
            if ($group->parent_id == $parentId) {
                $tree[] = [
                    'group'    => $group,
                    'depth'    => $depth,
                    'children' => self::build($groups, $group->id, $depth + 1),
                ];
            }
        }
        return $tree;
    }
}
