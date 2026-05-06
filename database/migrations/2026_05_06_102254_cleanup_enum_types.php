<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Úklid enum_types tabulky:
 *
 * Fáze 1 — smazání 11 mrtvých kategorií (44 hodnot).
 *   Sloupce, které je referencovaly, buď neexistují (Wireless norms,
 *   Polarizations, Media, Redirect action, backup platform) nebo
 *   neodpovídají uloženým ID (User types, Account types, Account kinds,
 *   Redirect destination) nebo jsou ve 100 % NULL (Wireless modes, Antennas).
 *
 * Fáze 2 — přejmenování Member types value na české labely odpovídající
 *   App\Helpers\MemberType (single source of truth pro UI). enum_types
 *   slouží jen jako admin metadata; members.type NENÍ FK na enum_types.id.
 *
 * Fáze 3 — `deprecated` flag + označení zastaralých Contact types
 *   (ICQ/Jabber/MSN/Skype). ICQ má 131 historických záznamů — záměrně
 *   nemažeme, jen schováme z dropdownu při vytváření nových.
 */
return new class extends Migration
{
    private const DEAD_TYPE_NAMES = [3, 5, 7, 8, 9, 10, 11, 12, 14, 15, 16];

    private const MEMBER_TYPE_LABELS = [
        1  => 'Žadatel',
        2  => 'Zákazník',
        3  => 'Čestný člen',
        4  => 'Sympatizant',
        5  => 'Nečlen',
        6  => 'Člen osvobozený od poplatků',
        15 => 'Bývalý člen',
        90 => 'Řádný člen',
    ];

    private const DEPRECATED_CONTACT_VALUES = ['ICQ', 'Jabber', 'MSN', 'Skype'];

    public function up(): void
    {
        // ── Fáze 3a — sloupec deprecated ────────────────────────────────────
        if (!Schema::hasColumn('enum_types', 'deprecated')) {
            Schema::table('enum_types', function (Blueprint $table) {
                $table->boolean('deprecated')->default(false)->after('read_only');
            });
        }

        DB::transaction(function () {
            // ── Fáze 1 — smazat 11 mrtvých kategorií + jejich hodnoty ──────
            DB::table('enum_types')
                ->whereIn('type_id', self::DEAD_TYPE_NAMES)
                ->delete();
            DB::table('enum_type_names')
                ->whereIn('id', self::DEAD_TYPE_NAMES)
                ->delete();

            // ── Fáze 2 — přejmenovat Member types na CZ labely ─────────────
            foreach (self::MEMBER_TYPE_LABELS as $id => $value) {
                DB::table('enum_types')
                    ->where('id', $id)
                    ->where('type_id', 1)
                    ->update(['value' => $value]);
            }

            // ── Fáze 3b — označit zastaralé Contact types ──────────────────
            DB::table('enum_types')
                ->where('type_id', 4)
                ->whereIn('value', self::DEPRECATED_CONTACT_VALUES)
                ->update(['deprecated' => 1]);
        });
    }

    public function down(): void
    {
        // Fáze 1 je destruktivní (smazaná data nebudeme rekonstruovat — nic
        // je nereferencovalo). down() reverzuje jen schema změnu z Fáze 3a.
        if (Schema::hasColumn('enum_types', 'deprecated')) {
            Schema::table('enum_types', function (Blueprint $table) {
                $table->dropColumn('deprecated');
            });
        }
    }
};
