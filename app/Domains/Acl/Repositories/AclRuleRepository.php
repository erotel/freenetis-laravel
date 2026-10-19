<?php

namespace App\Domains\Acl\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ACL pravidla (phpGACL schéma): acl (poznámka) + aco_map (akce view_all…)
 * + axo_map (sekce/hodnota objektu, kopie z axo — bez FK na axo) +
 * aro_groups_map (skupiny, kterým pravidlo platí).
 */
class AclRuleRepository
{
    public const ACTIONS = ['view_all', 'edit_all', 'new_all', 'delete_all', 'view_own', 'edit_own', 'new_own', 'delete_own'];

    public function find(int $id): ?object
    {
        return DB::table('acl')->where('id', $id)->first();
    }

    public function note(int $id): ?string
    {
        return DB::table('acl')->where('id', $id)->value('note');
    }

    /** Objekty (axo) pro formulář, podle sekce a jména. */
    public function objects(): Collection
    {
        return DB::table('axo')->orderBy('section_value')->orderBy('name')->get(['id', 'section_value', 'value', 'name']);
    }

    /** @return array{actions: string[], axo_ids: int[], group_ids: int[]} */
    public function selection(int $id): array
    {
        return [
            'actions'   => DB::table('aco_map')->where('acl_id', $id)->pluck('value')->toArray(),
            'axo_ids'   => DB::table('axo')
                ->join('axo_map', function ($j) use ($id) {
                    $j->on('axo.section_value', '=', 'axo_map.section_value')
                      ->on('axo.value', '=', 'axo_map.value')
                      ->where('axo_map.acl_id', $id);
                })
                ->pluck('axo.id')->toArray(),
            'group_ids' => DB::table('aro_groups_map')->where('acl_id', $id)->pluck('group_id')->toArray(),
        ];
    }

    /** Pravidla skupiny po řádcích objektů, seskupená podle acl_id. */
    public function forGroup(int $groupId): Collection
    {
        return DB::table('aro_groups_map as agm')
            ->join('acl', 'acl.id', '=', 'agm.acl_id')
            ->join('axo_map', 'axo_map.acl_id', '=', 'acl.id')
            ->where('agm.group_id', $groupId)
            ->select('acl.id as acl_id', 'acl.note', 'axo_map.section_value as section', 'axo_map.value as resource')
            ->orderBy('axo_map.section_value')
            ->orderBy('axo_map.value')
            ->get()
            ->groupBy('acl_id');
    }

    /** Pravidla mimo daná id (k přiřazení), podle id. */
    public function allExcept(array $ids): Collection
    {
        return DB::table('acl')->whereNotIn('id', $ids)->select('id', 'note')->orderBy('id')->get();
    }

    public function create(string $note): int
    {
        return (int) DB::table('acl')->insertGetId(['note' => $note]);
    }

    public function setNote(int $id, string $note): void
    {
        DB::table('acl')->where('id', $id)->update(['note' => $note]);
    }

    /** Přepíše akce, objekty a skupiny pravidla. */
    public function replaceMappings(int $id, array $actions, array $axoIds, array $groupIds): void
    {
        DB::table('aco_map')->where('acl_id', $id)->delete();
        foreach ($actions as $action) {
            DB::table('aco_map')->insert(['acl_id' => $id, 'value' => $action]);
        }

        DB::table('axo_map')->where('acl_id', $id)->delete();
        foreach (DB::table('axo')->whereIn('id', $axoIds)->get(['section_value', 'value']) as $axo) {
            DB::table('axo_map')->insert(['acl_id' => $id, 'section_value' => $axo->section_value, 'value' => $axo->value]);
        }

        DB::table('aro_groups_map')->where('acl_id', $id)->delete();
        foreach ($groupIds as $groupId) {
            DB::table('aro_groups_map')->insert(['acl_id' => $id, 'group_id' => $groupId]);
        }
    }

    public function delete(int $id): void
    {
        DB::table('aco_map')->where('acl_id', $id)->delete();
        DB::table('axo_map')->where('acl_id', $id)->delete();
        DB::table('aro_groups_map')->where('acl_id', $id)->delete();
        DB::table('acl')->where('id', $id)->delete();
    }
}
