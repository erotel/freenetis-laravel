<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Přidá stav `terminated` (Ukončená) do enumu contracts.status.
 *
 * Smlouvy jsou v samostatné DB `contractsdb` (connection 'contracts'), sdílené
 * s podpisovou "smlouvy" aplikací — schema není spravováno Laravel migracemi.
 * Tuto změnu ale řídíme odsud, protože nový stav používá jen FreenetIS admin
 * (výpověď zákazníka → "Ukončená"). Pro podpisovou app je hodnota navíc zpětně
 * kompatibilní (nikdy ji sama nezapíše).
 */
return new class extends Migration
{
    private string $conn = 'contracts';

    public function up(): void
    {
        DB::connection($this->conn)->statement(
            "ALTER TABLE contracts MODIFY status "
            . "enum('draft','otp_sent','otp_verified','signed','canceled','terminated') "
            . "NOT NULL DEFAULT 'draft'"
        );
    }

    public function down(): void
    {
        // Ukončené vrátit na signed, ať zúžení enumu neořízne data na prázdno.
        DB::connection($this->conn)->table('contracts')
            ->where('status', 'terminated')
            ->update(['status' => 'signed']);

        DB::connection($this->conn)->statement(
            "ALTER TABLE contracts MODIFY status "
            . "enum('draft','otp_sent','otp_verified','signed','canceled') "
            . "NOT NULL DEFAULT 'draft'"
        );
    }
};
