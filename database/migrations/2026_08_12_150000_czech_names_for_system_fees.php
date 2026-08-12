<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * České názvy systémových poplatků (fees.special_type_id 1–4).
 *
 * Jde jen o kosmetiku názvu — kód se řídí `special_type_id`, ne názvem,
 * takže přejmenování nic nerozbije (přerušení členství apod. běží dál).
 * Klíčujeme přes special_type_id (stabilní), aby to sedělo i na produkci.
 */
return new class extends Migration {
    private const NAMES = [
        1 => 'Přerušení členství',       // dřív "Membership interrupt"
        2 => 'Osvobozen od poplatku',    // dřív "Fee-free regular member"
        3 => 'Nečlen',                   // dřív "Non-member"
        4 => 'Čestný člen',              // dřív "Honorary member"
    ];

    private const ORIGINAL = [
        1 => 'Membership interrupt',
        2 => 'Fee-free regular member',
        3 => 'Non-member',
        4 => 'Honorary member',
    ];

    public function up(): void
    {
        foreach (self::NAMES as $specialType => $name) {
            DB::table('fees')->where('special_type_id', $specialType)->update(['name' => $name]);
        }
    }

    public function down(): void
    {
        foreach (self::ORIGINAL as $specialType => $name) {
            DB::table('fees')->where('special_type_id', $specialType)->update(['name' => $name]);
        }
    }
};
