<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Přidá ACL Debug_Controller#debug a přiřadí ho skupině System administrators (32).
 * Použito EnableDebugForAdmins middlewarem pro per-request app.debug toggle.
 *
 * Down: smaže přidaný řádek acl + jeho mapy + axo záznam, ale aco 'view_all'
 * nemažeme (sdílené napříč celým systémem).
 */
return new class extends Migration {
    private const AXO_SECTION = 'Debug_Controller';
    private const AXO_VALUE   = 'debug';
    private const ADMIN_GROUP = 32; // System administrators
    private const ACL_NOTE    = 'Debug mód: admin vidí stack trace / SQL / ENV v error stránce.';

    public function up(): void
    {
        // 1) axo (resource)
        $axoExists = DB::table('axo')
            ->where('section_value', self::AXO_SECTION)
            ->where('value', self::AXO_VALUE)
            ->exists();
        if (!$axoExists) {
            // axo.id není AUTO_INCREMENT (legacy schema z Kohana), MAX(id)+1 manuálně
            $nextAxoId = (int) DB::table('axo')->max('id') + 1;
            DB::table('axo')->insert([
                'id'            => $nextAxoId,
                'section_value' => self::AXO_SECTION,
                'value'         => self::AXO_VALUE,
                'name'          => 'Debug mód',
            ]);
        }

        // 2) acl (rule note) — vždy přidáme nový řádek, abychom jej mohli později
        //    bezpečně odstranit v down(). Pokud už existuje rule pro tento axo,
        //    nepřidáváme druhý.
        $existingAclId = DB::table('acl')
            ->join('axo_map', 'axo_map.acl_id', '=', 'acl.id')
            ->where('axo_map.section_value', self::AXO_SECTION)
            ->where('axo_map.value', self::AXO_VALUE)
            ->value('acl.id');

        if ($existingAclId) {
            return; // už zaseděno, nic nedělej
        }

        $aclId = DB::table('acl')->insertGetId([
            'note' => self::ACL_NOTE,
        ]);

        // 3) aco_map (action) — view_all stačí
        DB::table('aco_map')->insert([
            'acl_id' => $aclId,
            'value'  => 'view_all',
        ]);

        // 4) axo_map (resource link)
        DB::table('axo_map')->insert([
            'acl_id'        => $aclId,
            'section_value' => self::AXO_SECTION,
            'value'         => self::AXO_VALUE,
        ]);

        // 5) aro_groups_map (which group has it) — System administrators
        DB::table('aro_groups_map')->insert([
            'acl_id'   => $aclId,
            'group_id' => self::ADMIN_GROUP,
        ]);

        // 6) bump ACL cache generation, aby všichni adminové měli pravo hned
        \Illuminate\Support\Facades\Cache::increment('acl_cache_generation');
    }

    public function down(): void
    {
        $aclIds = DB::table('acl')
            ->join('axo_map', 'axo_map.acl_id', '=', 'acl.id')
            ->where('axo_map.section_value', self::AXO_SECTION)
            ->where('axo_map.value', self::AXO_VALUE)
            ->pluck('acl.id');

        if ($aclIds->isNotEmpty()) {
            DB::table('aco_map')->whereIn('acl_id', $aclIds)->delete();
            DB::table('axo_map')->whereIn('acl_id', $aclIds)->delete();
            DB::table('aro_groups_map')->whereIn('acl_id', $aclIds)->delete();
            DB::table('acl')->whereIn('id', $aclIds)->delete();
        }

        DB::table('axo')
            ->where('section_value', self::AXO_SECTION)
            ->where('value', self::AXO_VALUE)
            ->delete();

        \Illuminate\Support\Facades\Cache::increment('acl_cache_generation');
    }
};
