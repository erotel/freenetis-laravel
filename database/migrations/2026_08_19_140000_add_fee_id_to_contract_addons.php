<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apply-on-sign u dodatku „dodatečná služba": cílový poplatek (fees.id) se
 * ukládá jako NÁVRH do dodatku (`fee_id`) a na člena (members_fees) se přiřadí
 * teprve při podpisu (add → signServiceAddon), resp. deaktivuje při vydání
 * zrušení (remove → issueServiceRemoval). Bez cílového ID neměl dodatek podle
 * čeho poplatek po podpisu přiřadit/odebrat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('contracts')->table('contract_addons', function (Blueprint $table) {
            $table->unsignedInteger('fee_id')->nullable()->after('service_action');
        });
    }

    public function down(): void
    {
        Schema::connection('contracts')->table('contract_addons', function (Blueprint $table) {
            $table->dropColumn('fee_id');
        });
    }
};
