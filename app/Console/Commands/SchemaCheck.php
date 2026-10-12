<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hlídání driftu schématu: porovná živou DB s otiskem v repu
 * (database/schema/fingerprint.json = výchozí schéma + migrace). Ruční změna
 * na produkci (sloupec, typ, collation, index, view) se tak neschová — viz
 * remote_id / VARBINARY na .54 (Krok 4).
 *
 *   schema:check                         porovná DB z .env, drift → exit 1 + warning do logu
 *   schema:check --write --database=freenetis_test
 *                                        přegeneruje otisk z DB postavené jen z repa
 *                                        (po `testing:refresh-db`)
 *
 * Otisk nezahrnuje DEFINER, AUTO_INCREMENT ani data; jméno DB ve views se
 * normalizuje, takže freenetis_test i freenetis dají stejný otisk.
 */
class SchemaCheck extends Command
{
    protected $signature = 'schema:check
        {--write : přepsat otisk v repu místo porovnání}
        {--database= : DB ke čtení (výchozí z .env)}';

    protected $description = 'Porovná schéma DB s otiskem v repu (drift ručních změn).';

    public const FINGERPRINT = 'schema/fingerprint.json';

    public function handle(): int
    {
        $db   = (string) ($this->option('database') ?: config('database.connections.mysql.database'));
        $live = $this->fingerprint($db);
        $path = database_path(self::FINGERPRINT);

        if ($this->option('write')) {
            file_put_contents($path, json_encode($live, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            $this->info("Otisk z {$db} zapsán: " . count($live['tables']) . ' tabulek, ' . count($live['views']) . ' views.');
            return self::SUCCESS;
        }

        if (!is_file($path)) {
            $this->error("Chybí {$path} — vygeneruj ho přes --write.");
            return self::FAILURE;
        }
        $expected = json_decode((string) file_get_contents($path), true);
        $diff     = $this->diff($expected, $live);

        if ($diff === []) {
            $this->info("Schéma {$db} odpovídá repu (" . count($live['tables']) . ' tabulek, ' . count($live['views']) . ' views).');
            return self::SUCCESS;
        }

        foreach ($diff as $line) {
            $this->line("  {$line}");
        }
        $this->warn(count($diff) . " rozdílů mezi {$db} a repem.");
        Log::warning('schema.drift', ['database' => $db, 'count' => count($diff), 'diff' => array_slice($diff, 0, 50)]);
        return self::FAILURE;
    }

    /** @return array{tables: array<string, array>, views: array<string, string>} */
    public function fingerprint(string $db): array
    {
        $tables = [];
        foreach (DB::select("SELECT TABLE_NAME n, ENGINE e, TABLE_COLLATION c FROM information_schema.TABLES
                             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY 1", [$db]) as $t) {
            $tables[$t->n] = ['engine' => $t->e, 'collation' => $t->c, 'columns' => [], 'indexes' => []];
        }
        foreach (DB::select('SELECT TABLE_NAME t, COLUMN_NAME c, COLUMN_TYPE ty, IS_NULLABLE n, COLUMN_DEFAULT d, EXTRA x, COLLATION_NAME co
                             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$db]) as $c) {
            if (isset($tables[$c->t])) {
                $tables[$c->t]['columns'][$c->c] = implode(' | ', [
                    $c->ty, $c->n === 'YES' ? 'null' : 'not null', 'default ' . ($c->d ?? '∅'), $c->x ?: '-', $c->co ?? '-',
                ]);
            }
        }
        foreach (DB::select('SELECT TABLE_NAME t, INDEX_NAME i, NON_UNIQUE nu, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols
                             FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?
                             GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY 1, 2', [$db]) as $i) {
            if (isset($tables[$i->t])) {
                $tables[$i->t]['indexes'][$i->i] = ($i->nu ? 'index' : 'unique') . " ({$i->cols})";
            }
        }

        $views = [];
        foreach (DB::select('SELECT TABLE_NAME n, VIEW_DEFINITION d FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY 1', [$db]) as $v) {
            $views[$v->n] = str_replace("`{$db}`.", '', $v->d);
        }

        return ['tables' => $tables, 'views' => $views];
    }

    /** @return string[] lidsky čitelné rozdíly (repo → živá DB) */
    public function diff(array $expected, array $live): array
    {
        $out = [];
        foreach (['tables' => 'tabulka', 'views' => 'view'] as $kind => $label) {
            foreach (array_diff_key($expected[$kind], $live[$kind]) as $name => $_) {
                $out[] = "chybí {$label} {$name}";
            }
            foreach (array_diff_key($live[$kind], $expected[$kind]) as $name => $_) {
                $out[] = "navíc {$label} {$name} (není v repu)";
            }
        }
        foreach (array_intersect_key($expected['views'], $live['views']) as $name => $def) {
            if ($def !== $live['views'][$name]) {
                $out[] = "view {$name}: jiná definice";
            }
        }
        foreach (array_intersect_key($expected['tables'], $live['tables']) as $name => $exp) {
            $cur = $live['tables'][$name];
            foreach (['engine', 'collation'] as $k) {
                if ($exp[$k] !== $cur[$k]) {
                    $out[] = "{$name}: {$k} {$exp[$k]} → {$cur[$k]}";
                }
            }
            foreach (['columns' => 'sloupec', 'indexes' => 'index'] as $part => $label) {
                foreach ($exp[$part] as $col => $def) {
                    if (!isset($cur[$part][$col])) {
                        $out[] = "{$name}: chybí {$label} {$col}";
                    } elseif ($cur[$part][$col] !== $def) {
                        $out[] = "{$name}.{$col}: {$def}  →  {$cur[$part][$col]}";
                    }
                }
                foreach (array_diff_key($cur[$part], $exp[$part]) as $col => $def) {
                    $out[] = "{$name}: navíc {$label} {$col} ({$def})";
                }
            }
        }
        return $out;
    }
}
