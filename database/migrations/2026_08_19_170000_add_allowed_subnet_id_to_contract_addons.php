<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apply-on-sign u dodatku „přípojné místo": cílové místo (allowed_subnets.id)
 * se ukládá jako NÁVRH do dodatku (`allowed_subnet_id`). Účtování místa
 * (allowed_subnets.charged) se zapne teprve podpisem (add → signConnectionPoint
 * Addon), resp. vypne vydáním zrušení (remove → issueConnectionPointRemoval).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('contracts')->table('contract_addons', function (Blueprint $table) {
            $table->unsignedInteger('allowed_subnet_id')->nullable()->after('place_speed_name');
        });
    }

    public function down(): void
    {
        Schema::connection('contracts')->table('contract_addons', function (Blueprint $table) {
            $table->dropColumn('allowed_subnet_id');
        });
    }
};
