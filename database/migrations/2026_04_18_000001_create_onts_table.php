<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onts', function (Blueprint $table) {
            $table->id();
            $table->string('serial', 64);
            $table->string('gpon_port', 32);
            $table->unsignedTinyInteger('port_num');
            $table->bigInteger('port_index')->nullable();
            $table->unsignedInteger('ont_id');
            $table->unsignedInteger('service_port');
            $table->unsignedInteger('vlan');
            $table->enum('reg_status', ['new', 'registered', 'removed'])->default('new');
            $table->string('house_no', 32)->nullable();
            $table->string('user_name', 128)->nullable();
            $table->string('olt_ip', 45)->nullable();
            $table->integer('member_id')->nullable();
            $table->integer('device_id')->nullable();
            $table->timestamps();

            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            $table->index('serial');
            $table->index('olt_ip');
        });

        // Přesun existujících dat z gpon.onts (pokud tabulka existuje)
        try {
            $exists = DB::select("SELECT COUNT(*) as cnt FROM information_schema.tables
                WHERE table_schema = 'gpon' AND table_name = 'onts'");
            if (!empty($exists) && $exists[0]->cnt > 0) {
                DB::statement("INSERT INTO onts
                    (serial, gpon_port, port_num, port_index, ont_id, service_port, vlan,
                     reg_status, house_no, user_name, olt_ip, created_at, updated_at)
                    SELECT serial, gpon_port, port_num, port_index, ont_id, service_port, vlan,
                     reg_status, house_no, user_name, olt_ip, created_at, updated_at
                    FROM gpon.onts");
            }
        } catch (\Exception $e) {
            // gpon.onts neexistuje – přeskočit
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('onts');
    }
};
