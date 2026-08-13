<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFA fáze B (vynucení, NIS2/ZoKB): které ACL skupiny mají POVINNÉ dvoufázové
 * přihlášení. Přítomnost group_id = skupina vyžaduje MFA. Prázdná tabulka =
 * vynucení nikde (no-op) — proto je bezpečné nasadit vypnuté.
 *
 * Rozsah NIS2: označit jen admin/zápisové role, ne zákazníky.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mfa_required_groups')) {
            return;
        }
        Schema::create('mfa_required_groups', function (Blueprint $table) {
            $table->id();
            $table->integer('group_id');
            $table->timestamp('created_at')->nullable();
            $table->unique('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_required_groups');
    }
};
