<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Přejmenuj systémové zprávy z anglických názvů na české + přelož
     * pár zbývajících anglických textů (host (un)reachable, big debtor).
     * Aktualizujeme dle TYPE (ne ID), aby to fungovalo i kdyby ID
     * byly v jiné instalaci jinak.
     */
    public function up(): void
    {
        // ── name → translate by type ─────────────────────────────────────
        $nameByType = [
            // type => nový český název
            1  => 'Kontaktní údaje',
            2  => 'Stránka po zrušení přesměrování',
            3  => 'Neznámé zařízení',
            4  => 'Přerušení členství',
            5  => 'Dlužník (zákazník)',
            6  => 'Upozornění na placení (zákazník)',
            7  => 'Nepovolené přípojné místo',
            8  => 'Potvrzení přijaté platby',
            9  => 'Žádost o členství schválena',
            10 => 'Žádost o členství zamítnuta',
            11 => 'Žádost o připojení schválena',
            12 => 'Žádost o připojení zamítnuta',
            13 => 'Přijetí žádosti o připojení',
            14 => 'Zařízení {device_name} není dostupné',
            15 => 'Zařízení {device_name} je opět dostupné',
            16 => 'Vypršela doba testovacího připojení',
            17 => 'Přerušení členství — zahájeno',
            18 => 'Přerušení členství — ukončeno',
            19 => 'Bývalý člen',
            20 => 'Velký dlužník',
            21 => 'Bývalý člen — neplatil',
            22 => 'Bez e-mailu',
            23 => 'Bývalý člen — vrácení platby',
            24 => 'Potvrzení platby — zákazník',
        ];

        foreach ($nameByType as $type => $name) {
            DB::table('messages')->where('type', $type)->update(['name' => $name]);
        }

        // ── Anglické HTML/SMS texty → české ───────────────────────────────
        // id=23 (type=14): "Host {device_name} is unreachable since ..."
        DB::table('messages')->where('type', 14)->update([
            'email_text' => '<p>Zařízení {device_name} není dostupné od {state_changed_date}.</p>',
        ]);

        // id=24 (type=15): "Host {device_name} is again reachable since ..."
        DB::table('messages')->where('type', 15)->update([
            'email_text' => '<p>Zařízení {device_name} je opět dostupné od {state_changed_date}.</p>',
        ]);

        // id=86 (type=20): "Content of page for big debtors" — placeholder text,
        // přepíšeme na český základ, který lze v admin UI doupravit.
        DB::table('messages')->where('type', 20)->update([
            'text' => '<p><strong>Vážený zákazníku/člene,</strong></p>'
                    . '<p>na Vašem účtu evidujeme významný nedoplatek. Prosíme o jeho '
                    . 'neprodlené uhrazení, jinak bude Váš přístup k internetu omezen '
                    . 'a smlouva ukončena v souladu s VOP.</p>'
                    . '<p>Pro dotazy volejte HelpLinku <strong>588 207 234</strong>.</p>',
        ]);
    }

    public function down(): void
    {
        // Vrácení by přepsalo i případné admin úpravy v UI — záměrně no-op.
        // Pro skutečný rollback obnovte z backup-u DB.
    }
};
