<?php

namespace Database\Seeders;

use App\Services\AclService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed ACL records for the Gpon and Contracts modules.
 *
 * Idempotent — re-running only ensures the rows exist; it never duplicates.
 *
 * Group IDs:
 *   25 — Executive counsil
 *   29 — Tech 1 (engineering tree leaf shared by Tech 4/6/8/…)
 *   32 — System administrators
 *   45 — Pokladník
 */
class AclGponContractsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSection(
            section:    'Gpon_Controller',
            sectionLbl: 'GPON',
            resources:  [
                'gpon' => 'GPON správa',
                'olt'  => 'OLT zařízení',
                'ont'  => 'ONT zařízení',
            ],
            aclNote:    'GPON správa: System administrators a Engineers (Tech 1+).',
            groupIds:   [32, 29],   // System administrators + Tech 1 (a všichni potomci)
        );

        $this->seedSection(
            section:    'Contracts_Controller',
            sectionLbl: 'Smlouvy',
            resources:  [
                'contract' => 'Smlouva',
            ],
            // Pokladník + Rada nemá delete — chrání před omylem
            aclActionsByGroup: [
                32 => ['view_all', 'edit_all', 'new_all', 'delete_all'],   // admin
                25 => ['view_all', 'edit_all', 'new_all'],                 // Executive counsil
                45 => ['view_all', 'edit_all', 'new_all'],                 // Pokladník
            ],
            aclNote:    'Správa smluv: System administrators (plné), Executive counsil + Pokladník (bez mazání).',
            groupIds:   [32, 25, 45],
        );

        // Bumpni cache generation, aby se promítlo i u běžících workerů.
        app(AclService::class)->flushAllCache();
    }

    /**
     * Two call signatures for grouping policies:
     *   - simple: same actions for every group → pass `aclActions` (default = view/edit/new/delete _all)
     *   - granular: different actions per group → pass `aclActionsByGroup`
     */
    private function seedSection(
        string $section,
        string $sectionLbl,
        array $resources,
        string $aclNote,
        array $groupIds,
        array $aclActions = ['view_all', 'edit_all', 'new_all', 'delete_all'],
        array $aclActionsByGroup = [],
    ): void {
        // 1) axo_sections (id NOT auto_increment → musí se přidělit ručně)
        if (!DB::table('axo_sections')->where('value', $section)->exists()) {
            DB::table('axo_sections')->insert([
                'id'    => $this->nextId('axo_sections'),
                'value' => $section,
                'name'  => $sectionLbl,
            ]);
        }

        // 2) axo (jeden řádek na zdroj)
        foreach ($resources as $value => $name) {
            $exists = DB::table('axo')
                ->where('section_value', $section)
                ->where('value', $value)
                ->exists();
            if (!$exists) {
                DB::table('axo')->insert([
                    'id'            => $this->nextId('axo'),
                    'section_value' => $section,
                    'value'         => $value,
                    'name'          => $name,
                ]);
            }
        }

        // 3) ACL rule: jeden záznam (per skupinu, pokud se akce liší — viz níže), idempotentně podle `note`
        if (!empty($aclActionsByGroup)) {
            // Granular režim: oddělené ACL pravidlo na každou (akce-set, group)
            //   abychom zachovali "Council/Pokladník bez delete"
            foreach ($aclActionsByGroup as $gid => $actions) {
                $note  = $aclNote . ' [g' . $gid . ']';
                $aclId = $this->ensureAcl($note, $actions, array_keys($resources), $section, [$gid]);
            }
        } else {
            $this->ensureAcl($aclNote, $aclActions, array_keys($resources), $section, $groupIds);
        }
    }

    /**
     * Find-or-create the acl row identified by `note` and ensure its
     * aco_map / axo_map / aro_groups_map rows match the desired state.
     */
    private function ensureAcl(string $note, array $actions, array $resourceValues, string $section, array $groupIds): int
    {
        $aclId = DB::table('acl')->where('note', $note)->value('id');
        if (!$aclId) {
            $aclId = DB::table('acl')->insertGetId(['note' => $note]);
        }

        foreach ($actions as $action) {
            DB::table('aco_map')->updateOrInsert(
                ['acl_id' => $aclId, 'value' => $action],
                [],
            );
        }

        foreach ($resourceValues as $value) {
            DB::table('axo_map')->updateOrInsert(
                ['acl_id' => $aclId, 'section_value' => $section, 'value' => $value],
                [],
            );
        }

        foreach ($groupIds as $gid) {
            DB::table('aro_groups_map')->updateOrInsert(
                ['acl_id' => $aclId, 'group_id' => $gid],
                [],
            );
        }

        return (int) $aclId;
    }

    private function nextId(string $table): int
    {
        return (int) DB::table($table)->max('id') + 1;
    }
}
