<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;

/**
 * Automatický audit trail pro Eloquent model. Přidej `use Auditable;` do modelu
 * a jeho created/updated/deleted se zapíšou do `audit_logs` přes {@see AuditLogger}.
 *
 * Model může doladit:
 *   protected array $auditExclude = ['last_seen_at'];  // pole mimo audit
 *   protected array $auditRedact  = ['pin'];           // pole redigovat na '***'
 *
 * POZOR: Eloquent events se NEspustí u hromadných operací přes query builder
 * (Model::where(...)->update(...), DB::table(...)). Ta místa je nutné auditovat
 * ručně přes AuditLogger::log().
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            if (self::auditSkips($model, 'created')) {
                return;
            }
            AuditLogger::log(
                AuditLogger::CREATED,
                $model->getTable(),
                self::auditKey($model),
                null,
                $model->auditFilter($model->getAttributes())
            );
        });

        static::updated(function ($model) {
            if (self::auditSkips($model, 'updated')) {
                return;
            }
            $changes = $model->getChanges();
            if (empty($changes)) {
                return;
            }

            $old = [];
            foreach (array_keys($changes) as $key) {
                $old[$key] = $model->getOriginal($key);
            }

            AuditLogger::log(
                AuditLogger::UPDATED,
                $model->getTable(),
                self::auditKey($model),
                $model->auditFilter($old),
                $model->auditFilter($changes)
            );
        });

        static::deleted(function ($model) {
            if (self::auditSkips($model, 'deleted')) {
                return;
            }
            AuditLogger::log(
                AuditLogger::DELETED,
                $model->getTable(),
                self::auditKey($model),
                $model->auditFilter($model->getAttributes()),
                null
            );
        });
    }

    /**
     * Model může potlačit audit konkrétní změny (např. machine-heartbeat řádky
     * v key/value tabulce) definováním `auditShouldSkip(string $action): bool`.
     */
    protected static function auditSkips($model, string $action): bool
    {
        return method_exists($model, 'auditShouldSkip') && $model->auditShouldSkip($action);
    }

    /** Primární klíč jako int (null, pokud není číselný). */
    protected static function auditKey($model): ?int
    {
        $key = $model->getKey();
        return is_numeric($key) ? (int) $key : null;
    }

    /** Aplikuje per-model exclude/redact; globální redakci řeší AuditLogger. */
    public function auditFilter(array $values): array
    {
        $exclude = property_exists($this, 'auditExclude') ? (array) $this->auditExclude : [];
        $redact  = property_exists($this, 'auditRedact') ? (array) $this->auditRedact : [];

        $out = [];
        foreach ($values as $key => $value) {
            if (in_array($key, $exclude, true)) {
                continue;
            }
            $out[$key] = in_array($key, $redact, true) ? '***' : $value;
        }

        return $out;
    }
}
