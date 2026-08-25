<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPPoE credential vygenerovaný už při žádosti o připojení (captive onboarding).
 * Instalátor ho opíše do CPE hned na místě; při schválení žádosti se překlopí do
 * pppoe_secrets (na vzniklý iface) a RADIUS ho začne servírovat. Cleartext secret
 * (stejný důvod jako u pppoe_secrets — CHAP/MS-CHAPv2); v audit logu redigován.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connection_requests', function (Blueprint $table) {
            $table->string('pppoe_username', 64)->nullable()->after('mac_address');
            $table->string('pppoe_secret', 64)->nullable()->after('pppoe_username');
        });
    }

    public function down(): void
    {
        Schema::table('connection_requests', function (Blueprint $table) {
            $table->dropColumn(['pppoe_username', 'pppoe_secret']);
        });
    }
};
