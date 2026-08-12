<?php

use App\Services\MemberFeesTermination;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jednorázový úklid: u STÁVAJÍCÍCH bývalých členů/zákazníků (typ 15/16) zkrátit
 * individuální poplatky, které pořád „běží" po datu jejich odchodu, na
 * `leaving_date`. Dosud se to při odchodu nedělalo, tak visí s aktivním tarifem
 * (a teoreticky by se dál účtovali). Nově to řeší automatika
 * (MemberFeesTermination) — tady doháníme historii.
 *
 * Používá stejnou službu jako automatika: přerušení členství (special_type_id=1)
 * se nedotýká, mění jen poplatky s deactivation_date > leaving_date. Bere jen
 * členy s reálným leaving_date (9999/0000 = neurčeno → přeskočit).
 * down() je no-op — původní data-neurčité konce nelze spolehlivě obnovit.
 */
return new class extends Migration {
    public function up(): void
    {
        $formers = DB::table('members')
            ->whereIn('type', [15, 16])
            ->whereNotNull('leaving_date')
            ->whereNotIn('leaving_date', ['9999-12-31', '0000-00-00'])
            ->get(['id', 'leaving_date']);

        $members = 0;
        $fees = 0;
        foreach ($formers as $m) {
            $n = MemberFeesTermination::deactivate((int) $m->id, $m->leaving_date);
            if ($n > 0) {
                $members++;
                $fees += $n;
            }
        }

        echo "  Ukončeno poplatků bývalých členů: {$fees} (u {$members} členů)" . PHP_EOL;
    }

    public function down(): void
    {
        // Zpětně nelze spolehlivě obnovit (neznáme původní konce).
    }
};
