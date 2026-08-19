<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Šifrovací klíč (WPA2-PSK) pro přístupové body (type = AP). Ukládá se šifrovaně
 * at-rest stejně jako devices.password (App\Models\Concerns\EncryptsSensitive
 * Attributes) — proto TEXT (šifrovaný text je delší než plaintext).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->text('wpa_key')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('wpa_key');
        });
    }
};
