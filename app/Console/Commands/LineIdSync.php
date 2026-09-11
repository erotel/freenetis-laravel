<?php

namespace App\Console\Commands;

use App\Services\LineIdSyncService;
use Illuminate\Console\Command;

/**
 * Překlopí self-learning line-id ze staging `line_id_seen` (plněno RADIUS
 * accountingem) do `line_ids` (MAC → iface). Nespárované (neznámý MAC) nechá
 * jako onboarding kandidáty. Pouštět z cronu (např. každých 5 min).
 *
 * Viz [[project_pppoe_wpa2_nis2]] fáze B.
 */
class LineIdSync extends Command
{
    protected $signature   = 'lineid:sync';
    protected $description = 'Překlopí line-id z RADIUS accountingu (line_id_seen) do line_ids (MAC→iface).';

    public function handle(LineIdSyncService $svc): int
    {
        $r = $svc->reconcileFromSeen();
        $a = $svc->detectAnomalies();
        $cache = $r['cache_flushed'] === null
            ? 'beze změny rezervací'
            : ($r['cache_flushed'] ? 'Kea host-cache pročištěna' : 'POZOR: flush Kea cache selhal');
        $this->info(
            "line-id sync: {$r['reconciled']} spárováno, {$r['unmatched']} nespárováno (onboarding), "
            . "{$r['conflicts']} konfliktů, {$r['shared']} sdílených (VLAN/relay); anomálií: {$a['anomalies']}; {$cache}."
        );
        if ($r['cache_flushed'] === false) {
            $this->warn('Kea host-cache se nepodařilo pročistit — zkontroluj kea.control_nodes / creds; '
                . 'jinak nová rezervace nenaskočí, dokud cache nevyčistíš ručně.');
        }
        return self::SUCCESS;
    }
}
