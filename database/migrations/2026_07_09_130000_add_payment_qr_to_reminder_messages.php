<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vloží placeholder {payment_qr} (QR platba) do e-mailových šablon upozornění
 * na placení — typ 6 (zákazník) a 26 (řádný člen). Idempotentní: šablony, které
 * už QR obsahují, přeskočí. QR se umístí hned za řádek "výše platby je …",
 * s fallbackem za řádek s aktuální výší kreditu ({balance}).
 */
return new class extends Migration {
    private const QR_BLOCK = '<p> </p><div><strong>QR platba:</strong></div><p>{payment_qr}</p>';

    public function up(): void
    {
        foreach (DB::table('messages')->whereIn('type', [6, 26])->get() as $msg) {
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
                // Fallback: za řádek s aktuální výší kreditu (společný oběma šablonám).
                if (str_contains($text, '{balance}</strong></p>')) {
                    $new = str_replace('{balance}</strong></p>', '{balance}</strong></p>' . self::QR_BLOCK, $text);
                } else {
                    // Poslední možnost: připojit na konec.
                    $new = $text . self::QR_BLOCK;
                }
            }

            DB::table('messages')->where('id', $msg->id)->update(['email_text' => $new]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('messages')->whereIn('type', [6, 26])->get() as $msg) {
            $text = (string) $msg->email_text;
            if (!str_contains($text, '{payment_qr}')) {
                continue;
            }
            $new = str_replace(self::QR_BLOCK, '', $text);
            // Kdyby byl blok vložen ručně jinde, odstraníme aspoň samotný placeholder.
            $new = str_replace('{payment_qr}', '', $new);
            DB::table('messages')->where('id', $msg->id)->update(['email_text' => $new]);
        }
    }
};
