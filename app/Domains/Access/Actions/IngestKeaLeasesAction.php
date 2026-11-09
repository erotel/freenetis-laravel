<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Kea\KeaControlClient;
use App\Domains\Access\Repositories\LineIdRepository;
use App\Domains\Access\Support\RelayAgentInfo;
use Carbon\Carbon;

/**
 * Plní `line_id_seen` z aktivních leasů Kea — náhrada RADIUS accountingu, který
 * na Kea segmentech chybí (Kea RADIUS hook umí atributy z výrazů jen v access,
 * ne v accounting, a access se kvůli host-cache při stejném portu neopakuje).
 * Bez toho párování ({@see ReconcileLineIdsAction}) ani detektor anomálií
 * ({@see DetectLineIdAnomaliesAction}) na Kea segmentech nevidí výměnu MAC ani
 * druhé zařízení na portu.
 *
 * Option82 bere z `user-context.ISC.relay-agent-info.sub-options` (Kea ho ukládá
 * jen se `store-extended-info: true`). Leasy bez option82 přeskočí. Voláno
 * z `lineid:sync` před párováním.
 */
class IngestKeaLeasesAction
{
    public function __construct(
        private KeaControlClient $kea,
        private LineIdRepository $lines = new LineIdRepository(),
        private RelayAgentInfo $rai = new RelayAgentInfo(),
    ) {
    }

    /** @return array{ok:bool, leases:int, recorded:int, without_option82:int} */
    public function handle(): array
    {
        $leases = $this->kea->activeLeases4();
        if ($leases === null) {
            return ['ok' => false, 'leases' => 0, 'recorded' => 0, 'without_option82' => 0];
        }

        $recorded = 0;
        $without  = 0;
        foreach ($leases as $l) {
            $mac = strtoupper((string) ($l['hw-address'] ?? ''));
            if (!preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $mac)) {
                continue;
            }
            $sub = $this->rai->parseSubOptions($l['user-context']['ISC']['relay-agent-info']['sub-options'] ?? null);
            if ($sub['circuit'] === null || $sub['circuit'] === '') {
                $without++;
                continue;
            }
            // cltt = poslední komunikace klienta (renew) → odpovídá „naposledy viděn".
            $seenAt = isset($l['cltt']) ? Carbon::createFromTimestamp((int) $l['cltt']) : now();
            $changed = $this->lines->recordSeenFromLease(
                RelayAgentInfo::toSeenHex($sub['circuit']),
                RelayAgentInfo::toSeenHex($sub['remote']),
                $mac,
                $seenAt
            );
            $recorded += $changed ? 1 : 0;
        }

        return ['ok' => true, 'leases' => count($leases), 'recorded' => $recorded, 'without_option82' => $without];
    }
}
