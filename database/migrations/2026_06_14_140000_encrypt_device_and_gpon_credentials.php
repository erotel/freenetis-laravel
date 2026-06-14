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
 * Idempotentní: hodnoty, které už šifrované jsou (jdou dešifrovat), přeskočí —
 * lze tedy spustit opakovaně bez dvojího zašifrování.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->encryptColumn('devices', 'password');
        $this->encryptColumn('gpon_olts', 'snmp_auth_pass');
        $this->encryptColumn('gpon_olts', 'snmp_priv_pass');
    }

    public function down(): void
    {
        // Nevratná datová transformace — rollback záměrně neděláme
        // (nešifrovat zpět citlivá hesla automaticky).
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
