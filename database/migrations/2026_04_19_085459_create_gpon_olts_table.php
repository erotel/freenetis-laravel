<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gpon_olts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('ip', 45)->unique();
            $table->string('snmp_user', 100)->default('admin');
            $table->string('snmp_auth_pass', 100)->default('');
            $table->string('snmp_priv_pass', 100)->default('');
            $table->string('snmp_auth_proto', 10)->default('SHA');
            $table->string('snmp_priv_proto', 10)->default('AES');
            $table->string('line_prof', 100)->default('line-profile_default_0');
            $table->string('service_prof', 100)->default('sfu-aio-dmc');
            $table->string('traffic_table', 100)->default('int');
            $table->string('gpon_port', 20)->default('0/1/0');
            $table->integer('base_vlan')->nullable();
            $table->integer('port_count')->nullable();
            $table->json('vlan_map')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('gpon_olts')->insert([
            'name'            => 'Produkce MA5800',
            'ip'              => '10.133.170.2',
            'snmp_user'       => 'admin',
            'snmp_auth_pass'  => 'Pvfernet1',
            'snmp_priv_pass'  => 'Pvfernet1',
            'snmp_auth_proto' => 'SHA',
            'snmp_priv_proto' => 'AES',
            'line_prof'       => 'line-profile_default_0',
            'service_prof'    => 'sfu-aio-dmc',
            'traffic_table'   => 'int',
            'gpon_port'       => '0/2/0',
            'base_vlan'       => null,
            'port_count'      => null,
            'vlan_map'        => json_encode([
                '0' => 200, '1' => 200, '2' => 200, '3' => 200,
                '4' => 201, '5' => 201, '9' => 201,
                '6' => 202, '7' => 202, '8' => 202,
                '10' => 203, '11' => 203,
                '12' => 212, '13' => 213, '14' => 214, '15' => 215,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('gpon_olts')->insert([
            'name'            => 'Nový OLT',
            'ip'              => '10.133.67.99',
            'snmp_user'       => 'admin',
            'snmp_auth_pass'  => 'Pvfernet1',
            'snmp_priv_pass'  => 'Pvfernet1',
            'snmp_auth_proto' => 'SHA',
            'snmp_priv_proto' => 'AES',
            'line_prof'       => 'line-profile_default_0',
            'service_prof'    => 'sfu-aio-dmc',
            'traffic_table'   => 'int',
            'gpon_port'       => '0/1/0',
            'base_vlan'       => 200,
            'port_count'      => null,
            'vlan_map'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('gpon_olts');
    }
};
