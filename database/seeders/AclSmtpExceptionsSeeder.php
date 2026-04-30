<?php

namespace Database\Seeders;

use App\Services\AclService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Add the `smtp_exceptions` resource to the existing Network_Controller ACL rule.
 *
 * Stávající ACL řádek "nat a port" (id 128) pokrývá public_ip_nat + public_ports
 * pro skupiny 32 (System administrators) a 39 (Tech). SMTP výjimky mají mít stejná
 * práva, takže je jen přidáme jako další resource do téhož pravidla.
 *
 * Idempotent — opakované spuštění jen ujišťuje, že záznamy existují.
 */
class AclSmtpExceptionsSeeder extends Seeder
{
    public function run(): void
    {
        $section  = 'Network_Controller';
        $resource = 'smtp_exceptions';
        $resLabel = 'SMTP výjimky';

        // 1) axo (resource definition)
        $exists = DB::table('axo')
            ->where('section_value', $section)
            ->where('value', $resource)
            ->exists();
        if (!$exists) {
            DB::table('axo')->insert([
                'id'            => (int) DB::table('axo')->max('id') + 1,
                'section_value' => $section,
                'value'         => $resource,
                'name'          => $resLabel,
            ]);
        }

        // 2) Najdeme ACL pravidlo "nat a port" pro Network_Controller
        $aclId = DB::table('acl')
            ->join('axo_map', 'axo_map.acl_id', '=', 'acl.id')
            ->where('axo_map.section_value', $section)
            ->where('acl.note', 'nat a port')
            ->value('acl.id');

        if (!$aclId) {
            // Fallback: vezmi první ACL pravidlo, které už mapuje public_ports v Network_Controller
            $aclId = DB::table('axo_map')
                ->where('section_value', $section)
                ->where('value', 'public_ports')
                ->value('acl_id');
        }

        if ($aclId) {
            DB::table('axo_map')->updateOrInsert(
                ['acl_id' => $aclId, 'section_value' => $section, 'value' => $resource],
                [],
            );
        }

        // 3) Bumpni cache generaci, aby se promítlo i u běžících workerů
        app(AclService::class)->flushAllCache();
    }
}
