<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Izolace klientů od vnitřní sítě (10.133.0.0/16) — per-člen výjimky.
 *
 * Zákazník na zákaznickém segmentu routeru smí do vnitřní sítě jen na veřejné
 * služby (nastavení lan_isolation_public: DNS, FreenetIS, web). Výjimky pro
 * konkrétního člena = řádky této tabulky (jednotlivá IP nebo CIDR rozsah).
 * Router si je stahuje v DHCP exportu (LanIsolationService) jako address-listy.
 *
 * ACL: Members_Controller#lan_access (view_all = vidět, edit_all = přidat/smazat),
 * výchozí jen System administrators (32).
 */
return new class extends Migration
{
    private const AXO_SECTION = 'Members_Controller';
    private const AXO_VALUE   = 'lan_access';
    private const ADMIN_GROUP = 32; // System administrators

    public function up(): void
    {
        if (!Schema::hasTable('member_lan_access')) {
            Schema::create('member_lan_access', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('member_id');
                // Normalizovaný cíl: "a.b.c.d" (jedna IP) nebo "a.b.c.d/nn".
                $table->string('destination', 18);
                $table->string('comment', 255)->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamps();

                $table->unique(['member_id', 'destination']);
                $table->index('member_id');
            });
        }

        if (!DB::table('axo')->where('section_value', self::AXO_SECTION)->where('value', self::AXO_VALUE)->exists()) {
            // axo.id není AUTO_INCREMENT (legacy schema z Kohana), MAX(id)+1 manuálně
            DB::table('axo')->insert([
                'id'            => (int) DB::table('axo')->max('id') + 1,
                'section_value' => self::AXO_SECTION,
                'value'         => self::AXO_VALUE,
                'name'          => 'Přístup do vnitřní sítě',
            ]);
        }

        $exists = DB::table('acl')
            ->join('axo_map', 'axo_map.acl_id', '=', 'acl.id')
            ->where('axo_map.section_value', self::AXO_SECTION)
            ->where('axo_map.value', self::AXO_VALUE)
            ->exists();
        if (!$exists) {
            $aclId = DB::table('acl')->insertGetId([
                'note' => 'Přístup člena do vnitřní sítě (izolace klientů) — správa výjimek.',
            ]);
            DB::table('aco_map')->insert([
                ['acl_id' => $aclId, 'value' => 'view_all'],
                ['acl_id' => $aclId, 'value' => 'edit_all'],
            ]);
            DB::table('axo_map')->insert([
                'acl_id'        => $aclId,
                'section_value' => self::AXO_SECTION,
                'value'         => self::AXO_VALUE,
            ]);
            DB::table('aro_groups_map')->insert([
                'acl_id'   => $aclId,
                'group_id' => self::ADMIN_GROUP,
            ]);
        }

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
        DB::table('axo')->where('section_value', self::AXO_SECTION)->where('value', self::AXO_VALUE)->delete();

        Schema::dropIfExists('member_lan_access');

        \Illuminate\Support\Facades\Cache::increment('acl_cache_generation');
    }
};
