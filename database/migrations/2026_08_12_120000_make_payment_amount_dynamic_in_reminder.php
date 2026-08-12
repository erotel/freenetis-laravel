<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nahradí natvrdo napsanou částku (např. "320kč") ve frázi
 * "výše platby je : …" v upozornění na placení placeholderem {payment_amount},
 * který se počítá dynamicky z tarifu člena + dodatečných služeb
 * (PaymentQrService, stejná hodnota jako v QR platbě).
 *
 * Cílí na typy 6 (zákazník) a 26 (řádný člen), sloupce `text` i `email_text`.
 * Idempotentní: náhrada je ukotvená na číslici hned za "výše platby je :",
 * takže po první náhradě už žádná číslice nenásleduje a druhý běh nic nedělá.
 * (V DB má natvrdo částku aktuálně jen typ 6; u ostatních je to no-op.)
 */
return new class extends Migration {
    /** "výše platby je … : <číslo> kč" (s libovolnými mezerami, kč/Kč). */
    private const FIND = '/(výše platby je[^<0-9]*?)\d[\d\s.,]*\s*kč/iu';
    private const REPLACE = '${1}{payment_amount} Kč';

    /** Zpětná náhrada placeholderu na původní "320kč". */
    private const REVERT = '/(výše platby je[^<]*?)\{payment_amount\}\s*Kč/u';
    private const REVERT_REPLACE = '${1}320kč';

    public function up(): void
    {
        foreach (DB::table('messages')->whereIn('type', [6, 26])->get() as $msg) {
            $update = [];
            foreach (['text', 'email_text'] as $col) {
                $orig = (string) $msg->$col;
                if ($orig === '') {
                    continue;
                }
                $new = preg_replace(self::FIND, self::REPLACE, $orig, -1, $count);
                if ($count) {
                    $update[$col] = $new;
                }
            }
            if ($update) {
                DB::table('messages')->where('id', $msg->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('messages')->whereIn('type', [6, 26])->get() as $msg) {
            $update = [];
            foreach (['text', 'email_text'] as $col) {
                $orig = (string) $msg->$col;
                if ($orig === '') {
                    continue;
                }
                $new = preg_replace(self::REVERT, self::REVERT_REPLACE, $orig, -1, $count);
                if ($count) {
                    $update[$col] = $new;
                }
            }
            if ($update) {
                DB::table('messages')->where('id', $msg->id)->update($update);
            }
        }
    }
};
