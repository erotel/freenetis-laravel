<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MAC-anomaly detektor pro IPoE line-id (fáze B). Line-id staví na tom, že
 * identita = fyzický port; kdo přehodí porty na switchi, získá IP souseda.
 * Detektor to zachytí porovnáním REÁLNĚ viděné MAC (line_id_seen) proti
 * ZAVEDENÉMU mapování (line_ids) a REGISTROVANÉ MAC přípojky (ifaces.mac).
 *
 * type:     identity_cross (MAC jiného registrovaného zákazníka na cizím portu)
 *           mac_moved      (registrovaná MAC na jiném circuit-id než svém)
 *           unknown_device (neznámá MAC na známém portu — nejspíš výměna routeru)
 * severity: critical / high / warning
 *
 * Plní `lineid:sync` (detectAnomalies), zobrazuje UI stránka /line-id-anomalies.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('line_id_anomalies')) {
            return;
        }
        Schema::create('line_id_anomalies', function (Blueprint $table) {
            $table->increments('id');
            $table->string('circuit_id', 255);                       // port, kde anomálie
            $table->unsignedInteger('expected_iface_id')->nullable(); // kdo tam má být (line_ids)
            $table->string('seen_mac', 32);                          // reálně viděná MAC
            $table->unsignedInteger('seen_iface_id')->nullable();     // čí je ta MAC (pokud registr.)
            $table->string('type', 32);
            $table->string('severity', 16);
            $table->unsignedInteger('seen_count')->default(1);
            $table->timestamp('first_seen')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['circuit_id', 'seen_mac'], 'uq_anom');
            $table->index('resolved_at');
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_id_anomalies');
    }
};
