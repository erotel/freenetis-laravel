<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vloží placeholder {payment_qr} (QR platba) do e-mailových šablon upozornění
 * na nedostatečnou výši konta — typ 5 (zákazník) a 25 (řádný člen). Doplněk
 * k ..._add_payment_qr_to_reminder_messages (typy 6/26). Idempotentní:
 * šablony, které už QR obsahují, přeskočí.
 *
 * Kotva pro umístění QR (v pořadí priority):
 *   1) za řádek "výše platby je …" (typ 25),
 *   2) za řádek "variabilní symbol {variable_symbol}" (typ 5),
 *   3) za řádek s aktuální výší kreditu ({balance}),
 *   4) na konec těla.
 */
return new class extends Migration {
    private const QR_BLOCK = '<p> </p><div><strong>QR platba:</strong></div><p>{payment_qr}</p>';

    public function up(): void
    {
        foreach (DB::table('messages')->whereIn('type', [5, 25])->get() as $msg) {
            $text = (string) $msg->email_text;
            if ($text === '' || str_contains($text, '{payment_qr}')) {
                continue;
            }

            $new = preg_replace(
                '/(<p><strong>výše platby je[^<]*<\/strong><\/p>)/u',
                '$1' . self::QR_BLOCK,
                $text,
                1,
                $count
            );

            if (!$count) {
                // Typ 5: za řádek s variabilním symbolem. Kotvíme přímo na
                // "{variable_symbol}</strong></p>" — mezi "symbol" a placeholderem
                // je v šabloně nedělitelná mezera (U+00A0), tak ji neřešíme.
                $new = preg_replace(
                    '/(\{variable_symbol\}<\/strong><\/p>)/u',
                    '$1' . self::QR_BLOCK,
                    $text,
                    1,
                    $count
                );
            }

            if (!$count) {
                if (str_contains($text, '{balance}</strong></p>')) {
                    $new = str_replace('{balance}</strong></p>', '{balance}</strong></p>' . self::QR_BLOCK, $text);
                } else {
                    $new = $text . self::QR_BLOCK;
                }
            }

            DB::table('messages')->where('id', $msg->id)->update(['email_text' => $new]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('messages')->whereIn('type', [5, 25])->get() as $msg) {
            $text = (string) $msg->email_text;
            if (!str_contains($text, '{payment_qr}')) {
                continue;
            }
            $new = str_replace(self::QR_BLOCK, '', $text);
            $new = str_replace('{payment_qr}', '', $new);
            DB::table('messages')->where('id', $msg->id)->update(['email_text' => $new]);
        }
    }
};
