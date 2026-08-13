<?php

namespace App\Services;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Jednotný zápis audit trailu (NIS2 / ZoKB) do `audit_logs`.
 *
 * Design:
 *  - Zápis NIKDY nesmí shodit uživatelskou akci — vše je v try/catch, chyba
 *    auditu jen spadne do aplikačního logu.
 *  - Citlivá pole (hesla, tokeny) se redigují na '***' (config `audit.redact`).
 *  - Prázdné změny (update bez reálné změny sledovaných polí) se nezapisují.
 *  - `user_id` je z přihlášeného uživatele; v cronu/konzoli je null (systém).
 *
 * Volání buď automaticky přes {@see \App\Models\Concerns\Auditable} trait na
 * Eloquent modelech, nebo ručně u mutací přes DB::table():
 *   AuditLogger::log('updated', 'members', $id, $old, $new);
 */
class AuditLogger
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const DELETED = 'deleted';

    /**
     * Zapíše jeden auditní záznam.
     *
     * @param string        $action   'created' | 'updated' | 'deleted' | vlastní (např. 'approved')
     * @param string        $table    název tabulky/entity, např. 'members'
     * @param int|null      $objectId primární klíč dotčeného objektu
     * @param array|null    $old      hodnoty před změnou (u update jen změněná pole)
     * @param array|null    $new      hodnoty po změně (u update jen změněná pole)
     * @param int|null      $userId   override uživatele (jinak Auth::id())
     */
    public static function log(
        string $action,
        string $table,
        ?int $objectId,
        ?array $old = null,
        ?array $new = null,
        ?int $userId = null
    ): void {
        try {
            if (!config('audit.enabled', true)) {
                return;
            }
            if (in_array($table, (array) config('audit.ignore_tables', []), true)) {
                return;
            }

            $old = self::clean($old);
            $new = self::clean($new);

            // Update bez reálné změny sledovaných polí neukládáme.
            if ($action === self::UPDATED && empty($old) && empty($new)) {
                return;
            }

            DB::table('audit_logs')->insert([
                'occurred_at'    => now(),
                'user_id'        => $userId ?? Auth::id(),
                'ip_address'     => self::clientIp(),
                'action'         => $action,
                'auditable_type' => $table,
                'auditable_id'   => $objectId,
                'old_values'     => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'new_values'     => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $e) {
            // Audit se nikdy nesmí stát důvodem selhání samotné akce.
            Log::error('AuditLogger selhal: ' . $e->getMessage(), [
                'action' => $action,
                'table'  => $table,
                'object' => $objectId,
            ]);
        }
    }

    /**
     * Odstraní ignorované sloupce a rediguje citlivá pole.
     * Vrací null pro null vstup (zachová „nezaznamenáno" vs. „prázdné").
     */
    private static function clean(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $exclude = (array) config('audit.exclude_columns', []);
        $redact  = (array) config('audit.redact', []);

        $out = [];
        foreach ($values as $key => $value) {
            if (in_array($key, $exclude, true)) {
                continue;
            }
            $out[$key] = in_array($key, $redact, true) ? '***' : $value;
        }

        return $out;
    }

    /** IP klienta; v konzoli/cronu null (systémová akce). */
    private static function clientIp(): ?string
    {
        if (App::runningInConsole()) {
            return null;
        }

        try {
            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }
}
