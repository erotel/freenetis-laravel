<?php

namespace Database\Seeders;

use App\Services\AclService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ACL pro modul Schůze členů (Meetings_Controller#meeting).
 * Idempotentní. Skupiny: 32 = System administrators, 25 = Executive counsil (Rada).
 */
class AclMeetingsSeeder extends Seeder
{
    public function run(): void
    {
        $section = 'Meetings_Controller';

        if (!DB::table('axo_sections')->where('value', $section)->exists()) {
            DB::table('axo_sections')->insert([
                'id'    => $this->nextId('axo_sections'),
                'value' => $section,
                'name'  => 'Schůze členů',
            ]);
        }

        if (!DB::table('axo')->where('section_value', $section)->where('value', 'meeting')->exists()) {
            DB::table('axo')->insert([
                'id'            => $this->nextId('axo'),
                'section_value' => $section,
                'value'         => 'meeting',
                'name'          => 'Schůze / prezence',
            ]);
        }

        // Admin (32) + Rada (25): plná práva (view/new/edit). delete neřešíme.
        $this->ensureAcl(
            'Schůze členů: System administrators + Executive counsil.',
            ['view_all', 'new_all', 'edit_all'],
            ['meeting'],
            $section,
            [32, 25],
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
