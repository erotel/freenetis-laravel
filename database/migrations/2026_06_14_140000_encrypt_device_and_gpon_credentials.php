<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jednorázově zašifruje existující citlivé přístupy uložené v plaintextu:
 *  - devices.password (heslo k zařízení)
 *  - gpon_olts.snmp_auth_pass / snmp_priv_pass (SNMPv3 hesla OLT)
 *
 * Sloupce jsou původně dimenzované na krátký plaintext (např. VARCHAR(30)),
 * šifrovaný text (Laravel Crypt) má ~200+ znaků → sloupce nejdřív rozšíříme
 * na TEXT a teprve pak hodnoty zašifrujeme.
 *
 * Idempotentní: hodnoty, které už šifrované jsou (jdou dešifrovat), přeskočí —
 * lze spustit opakovaně bez dvojího zašifrování.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1) rozšířit sloupce, ať se vejde šifrovaný blob
        $this->widenColumn('devices', 'password');
        $this->widenColumn('gpon_olts', 'snmp_auth_pass');
        $this->widenColumn('gpon_olts', 'snmp_priv_pass');

        // 2) zašifrovat existující plaintext hodnoty
        $this->encryptColumn('devices', 'password');
        $this->encryptColumn('gpon_olts', 'snmp_auth_pass');
        $this->encryptColumn('gpon_olts', 'snmp_priv_pass');
    }

    public function down(): void
    {
        // Nevratná datová transformace — rollback záměrně neděláme
        // (nešifrovat zpět citlivá hesla automaticky a neměnit šířku sloupců).
    }

    private function widenColumn(string $table, string $column): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }
        // Raw MODIFY (bez doctrine/dbal); TEXT NULL pojme šifrovaný text libovolné délky.
        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` TEXT NULL");
    }

    private function encryptColumn(string $table, string $column): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)->select('id', $column)->orderBy('id')->each(function ($row) use ($table, $column) {
            $value = $row->{$column};
            if ($value === null || $value === '') {
                return;
            }
            try {
                Crypt::decryptString($value);
                return; // už zašifrované → přeskoč
            } catch (DecryptException $e) {
                // plaintext → zašifruj
            }
            DB::table($table)->where('id', $row->id)->update([
                $column => Crypt::encryptString($value),
            ]);
        });
    }
};
