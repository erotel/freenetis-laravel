<?php

namespace Tests\Feature\Access;

use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * radacct píše FreeRADIUS (sql_acct z .54 i Kea uzlů) se strict sql_mode —
 * příliš dlouhá hodnota = zahozený záznam session. MikroTik DHCP relay posílá
 * jméno subnetu (Called-Station-Id) a DHCP client-id (Calling-Station-Id).
 */
class RadacctContractTest extends DatabaseTestCase
{
    public function test_dlouhe_station_id_z_dhcp_relaye_se_vejdou(): void
    {
        DB::statement('SET SESSION sql_mode = @@GLOBAL.sql_mode'); // jako FreeRADIUS (strict)
        $called  = str_repeat('NET_102.0_25_Mozartova-Smerala_Smeral21_23_', 3);            // 132
        $calling = 'ff:3e:75:b2:99:0:1:0:1:28:b3:47:3:0:16:3e:75:b2:99:0:1:0:1:28:b3:47:3:0:16:3e'; // 79

        DB::table('radacct')->insert([
            'acctuniqueid' => '__selftest_' . substr(md5(uniqid()), 0, 16),
            'username' => '00:16:3E:75:B2:99', 'nasipaddress' => '10.133.230.2',
            'calledstationid' => $called, 'callingstationid' => $calling,
        ]);

        $this->assertTrue(DB::table('radacct')->where('calledstationid', $called)->where('callingstationid', $calling)->exists());
    }
}
