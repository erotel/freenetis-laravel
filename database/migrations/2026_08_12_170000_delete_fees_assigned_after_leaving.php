<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Smazání chybných přiřazení poplatku bývalým členům: řádky `members_fees`, kde
 * poplatek začal (activation_date) AŽ PO datu odchodu člena (typ 15/16). Vznikly
 * zjevně hromadným přiřazením, které omylem zasáhlo už odešlé členy — takový
 * poplatek nemá platnost (odchod je před jeho začátkem) a jde smazat.
 *
 * Přerušení členství (special_type_id=1) se nedotýká. down() je no-op.
 */
return new class extends Migration {
    public function up(): void
    {
        $ids = DB::table('members_fees as mf')
            ->join('members as m', 'm.id', '=', 'mf.member_id')
            ->leftJoin('fees as f', 'f.id', '=', 'mf.fee_id')
            ->whereIn('m.type', [15, 16])
            ->whereNotNull('m.leaving_date')
            ->whereNotIn('m.leaving_date', ['9999-12-31', '0000-00-00'])
            ->whereColumn('mf.activation_date', '>', 'm.leaving_date')
            ->where(function ($q) {
                $q->whereNull('f.special_type_id')->orWhere('f.special_type_id', '!=', 1);
            })
            ->pluck('mf.id')
            ->all();

        $deleted = $ids ? DB::table('members_fees')->whereIn('id', $ids)->delete() : 0;

        echo "  Smazáno chybných poplatků (přiřazených bývalým členům po odchodu): {$deleted}" . PHP_EOL;
    }

    public function down(): void
    {
        // Smazané chybné řádky nelze spolehlivě obnovit.
    }
};
