<?php

namespace App\Console\Commands;

use App\Services\LineIdSyncService;
use Illuminate\Console\Command;

/**
 * Pročistí host-cache všech Kea uzlů (HTTP control API z kea.control_nodes, nebo
 * fallback na lokální unix socket). Volá se automaticky z lineid:sync po změně
 * rezervací; tento příkaz je ruční cesta (runbook po cutoveru, nebo když je flush
 * z www-data potřeba dohnat). Viz [[project_lineid_tr101_gotcha]], [[project_kea_ipoe_fiber]].
 */
class KeaFlushHostCache extends Command
{
    protected $signature   = 'kea:flush-host-cache';
    protected $description = 'Pročistí host-cache Kea uzlů (control API) — obnova po změně line-id rezervací.';

    public function handle(LineIdSyncService $svc): int
    {
        if ($svc->flushKeaHostCache()) {
            $this->info('Kea host-cache pročištěna.');
            return self::SUCCESS;
        }
        $nodes = implode(', ', (array) config('kea.control_nodes', []));
        $this->error('Flush se nezdařil (uzel nedostupný / creds / Kea neběží). Cíle: '
            . ($nodes !== '' ? $nodes : 'socket ' . config('kea.control_socket')));
        return self::FAILURE;
    }
}
