<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-subnet přepínač IPoE (line-id / option82). Zapnutím se subnet přepne
 * z modelu statických MAC leasů na přidělování IP podle line-id přes RADIUS:
 *   - DHCP export nastaví na MK dhcp-serveru `use-radius=yes` a PŘESTANE
 *     generovat statické leasy pro ten subnet → MK se ptá RADIUSu,
 *   - RADIUS views (radreply/radcheck_lineid_v) vrací IP jen pro ipoe=1 subnety.
 *
 * Umožňuje postupný cutover subnet po subnetu (hloupé switche se mění postupně;
 * tam kde jsou zákazníci přímo na managed switchi s option82, jde zapnout hned).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('subnets', 'ipoe')) {
            Schema::table('subnets', function (Blueprint $table) {
                $table->boolean('ipoe')->default(false)->after('dns');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subnets', 'ipoe')) {
            Schema::table('subnets', function (Blueprint $table) {
                $table->dropColumn('ipoe');
            });
        }
    }
};
