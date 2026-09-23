<?php

namespace Database\Seeders;

use App\Services\AclService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Self-service export přihlášky: řádní členové (typ 90) si smí stáhnout SVOJI
 * přihlášku. Přidá právo view_own na Members_Controller#registration_export
 * skupině 22 „Regular members" (do ní patří přihlašovací uživatelé řádných členů).
 *
 * Bezpečnost: controller (registrationExport) u view_own vynucuje own + jen typ
 * 'registration'; ukončení/výpověď a odeslání e-mailem zůstává staff (view_all).
 * Idempotentní.
 */
class AclRegistrationExportOwnSeeder extends Seeder
{
    public function run(): void
    {
        $section = 'Members_Controller';
        $value   = 'registration_export';

        // Sekce i zdroj by měly existovat (view_all používá staff) — pro jistotu doplň.
        if (!DB::table('axo_sections')->where('value', $section)->exists()) {
            DB::table('axo_sections')->insert([
                'id' => $this->nextId('axo_sections'), 'value' => $section, 'name' => 'Členové',
            ]);
        }
        if (!DB::table('axo')->where('section_value', $section)->where('value', $value)->exists()) {
            DB::table('axo')->insert([
                'id' => $this->nextId('axo'), 'section_value' => $section,
                'value' => $value, 'name' => 'Export přihlášky',
            ]);
        }

        // Řádní členové (skupina 22): jen view_own na registration_export.
        $this->ensureAcl(
            'Self-service export přihlášky: Regular members (view_own).',
            ['view_own'],
            [$value],
            $section,
            [22],
        );

        app(AclService::class)->flushAllCache();
    }

    private function ensureAcl(string $note, array $actions, array $resourceValues, string $section, array $groupIds): int
    {
        $aclId = DB::table('acl')->where('note', $note)->value('id');
        if (!$aclId) {
            $aclId = DB::table('acl')->insertGetId(['note' => $note]);
        }
        foreach ($actions as $action) {
            DB::table('aco_map')->updateOrInsert(['acl_id' => $aclId, 'value' => $action], []);
        }
        foreach ($resourceValues as $value) {
            DB::table('axo_map')->updateOrInsert(['acl_id' => $aclId, 'section_value' => $section, 'value' => $value], []);
        }
        foreach ($groupIds as $gid) {
            DB::table('aro_groups_map')->updateOrInsert(['acl_id' => $aclId, 'group_id' => $gid], []);
        }
        return (int) $aclId;
    }

    private function nextId(string $table): int
    {
        return (int) DB::table($table)->max('id') + 1;
    }
}
