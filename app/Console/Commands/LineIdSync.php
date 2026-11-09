<?php

namespace App\Console\Commands;

use App\Domains\Access\Actions\DetectLineIdAnomaliesAction;
use App\Domains\Access\Actions\IngestKeaLeasesAction;
use App\Domains\Access\Actions\ReconcileLineIdsAction;
use Illuminate\Console\Command;

/**
 * Překlopí self-learning line-id ze staging `line_id_seen` (plněno RADIUS
 * accountingem od MikroTiků a z leasů Kea) do `line_ids` (MAC → iface). Nespárované (neznámý MAC) nechá
 * jako onboarding kandidáty. Pouštět z cronu (např. každých 5 min).
 *
 * Viz [[project_pppoe_wpa2_nis2]] fáze B.
 */
class LineIdSync extends Command
{
    protected $signature   = 'lineid:sync';
    protected $description = 'Překlopí line-id z RADIUS accountingu (line_id_seen) do line_ids (MAC→iface).';

    public function handle(IngestKeaLeasesAction $ingest, ReconcileLineIdsAction $reconcile, DetectLineIdAnomaliesAction $detect): int
    {
        // Kea segmenty nemají RADIUS accounting → pozorování MAC na portu bereme z leasů.
        $k = $ingest->handle();
        if ($k['ok']) {
            $this->info("Kea leasy: {$k['leases']} aktivních, {$k['recorded']} nových/posunutých pozorování"
                . ($k['without_option82'] ? ", {$k['without_option82']} bez option82 (store-extended-info?)" : '') . '.');
        } else {
            $this->line('Kea leasy: Kea nedostupná nebo nenastavená (kea.control_nodes) — přeskočeno.');
        }

        $r = $reconcile->handle();
        $a = $detect->handle();
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
