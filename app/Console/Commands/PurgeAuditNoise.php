<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Jednorázový úklid telemetrického šumu z audit_logs, který vznikl DŘÍV, než
 * se doplnilo filtrování (exclude_columns + Setting::auditShouldSkip).
 *
 * Maže JEN „updated" řádky, které jsou po odečtení telemetrických sloupců
 * prázdné (machine-heartbeat), a config heartbeat klíče (cron_last_active,
 * cron_state, redirection_state…) rozpoznané podle timestamp/unix hodnoty.
 * Vytvoření/smazání a smysluplné změny se NEMAŽOU.
 *
 * Bezpečné: default --dry-run jen spočítá. Ostré smazání až s potvrzením.
 */
class PurgeAuditNoise extends Command
{
    protected $signature = 'audit:purge-noise {--dry-run : Jen spočítat, nemazat} {--force : Smazat bez interaktivního potvrzení}';

    protected $description = 'Smaže telemetrický šum (access_time, dhcp flagy, cron heartbeat) z audit_logs';

    public function handle(): int
    {
        $exclude = (array) config('audit.exclude_columns', []);
        $ids = [];

        DB::table('audit_logs')->orderBy('id')->chunk(2000, function ($rows) use (&$ids, $exclude) {
            foreach ($rows as $r) {
                if ($this->isNoise($r, $exclude)) {
                    $ids[] = $r->id;
                }
            }
        });

        $count = count($ids);
        $this->info("Nalezeno telemetrického šumu: {$count} řádků.");

        if ($count === 0) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line('(dry-run — nic se nemaže)');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm("Smazat {$count} řádků?")) {
            $this->warn('Zrušeno.');
            return self::SUCCESS;
        }

        $deleted = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $deleted += DB::table('audit_logs')->whereIn('id', $chunk)->delete();
        }
        $this->info("Smazáno: {$deleted} řádků.");

        return self::SUCCESS;
    }

    /** Je to čistě telemetrický řádek (bez auditní hodnoty)? */
    private function isNoise(object $r, array $exclude): bool
    {
        // Vytvoření/smazání i vlastní akce (fee_deduction…) necháváme být.
        if ($r->action !== 'updated') {
            return false;
        }

        $new = $this->strip(json_decode($r->new_values ?? 'null', true), $exclude);
        $old = $this->strip(json_decode($r->old_values ?? 'null', true), $exclude);

        // Po odečtení telemetrických sloupců nezbylo nic → access_time / dhcp flagy.
        if (empty($new) && empty($old)) {
            return true;
        }

        // Config heartbeat: zbyla jen {value: <timestamp|unix>}.
        if ($r->auditable_type === 'config'
            && array_keys($new) === ['value']
            && is_scalar($new['value'])
        ) {
            $v = (string) $new['value'];
            if (preg_match('/^\d{9,}$/', $v) || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $v)) {
                return true;
            }
        }

        return false;
    }

    private function strip($arr, array $exclude): array
    {
        if (!is_array($arr)) {
            return [];
        }
        foreach ($exclude as $k) {
            unset($arr[$k]);
        }
        return $arr;
    }
}
