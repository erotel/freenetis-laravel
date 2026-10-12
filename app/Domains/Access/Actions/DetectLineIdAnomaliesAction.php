<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Repositories\LineIdRepository;
use App\Domains\Access\Support\CircuitIdParser;
use App\Domains\Access\Support\SharedCircuitDetector;

/**
 * MAC-anomaly detekce nad nedávnými záznamy `line_id_seen`: porovná reálně
 * viděnou MAC proti zavedenému mapování a registrované MAC → idempotentní
 * upsert do `line_id_anomalies` (typy viz migrace).
 */
class DetectLineIdAnomaliesAction
{
    public function __construct(
        private LineIdRepository $lines = new LineIdRepository(),
        private CircuitIdParser $parser = new CircuitIdParser(),
        private SharedCircuitDetector $shared = new SharedCircuitDetector(),
    ) {
    }

    /** @return array{anomalies:int} */
    public function handle(int $recentDays = 7): array
    {
        $found = 0;
        $since = now()->subDays($recentDays);

        foreach ($this->lines->seenSince($since) as $row) {
            $circuit = $this->parser->decodeHex($row->circuit_id_hex);
            if ($circuit === null || $circuit === '') {
                continue;
            }
            $remote = $this->parser->decodeHex($row->remote_id_hex) ?? '';

            // Sdílený relay/VLAN circuit-id (Vlanif / víc MACů na téže dvojici) není
            // per-zákazník identita → víc MACů je tam normální, NEflagovat.
            if ($this->shared->isShared($circuit, $row->circuit_id_hex, $row->remote_id_hex)) {
                continue;
            }

            $seenIfaceId     = $this->lines->ifaceIdByMac($row->mac);
            $line            = $this->lines->find($circuit, $remote);
            $expectedIfaceId = $line ? (int) $line->iface_id : null;

            $type = null;
            $severity = null;

            if ($expectedIfaceId !== null && $seenIfaceId !== null && $seenIfaceId !== $expectedIfaceId) {
                // MAC jiného REGISTROVANÉHO zákazníka na cizím portu = křížení identit.
                $type = 'identity_cross';
                $severity = 'critical';
            } elseif ($expectedIfaceId !== null && $seenIfaceId === null) {
                // Neznámá MAC na známém portu (nejspíš výměna routeru / rogue).
                $type = 'unknown_device';
                $severity = 'warning';
            } elseif ($expectedIfaceId === null && $seenIfaceId !== null) {
                // Port není v line_ids, ale MAC patří registrované iface, která má
                // svůj domovský port jinde → přesun/klon MAC.
                $home = $this->lines->findByIface($seenIfaceId);
                if ($home && $home->circuit_id !== $circuit) {
                    $type = 'mac_moved';
                    $severity = 'high';
                }
            }

            if ($type === null) {
                continue; // OK nebo onboarding kandidát (neanomálie)
            }

            $this->lines->recordAnomaly($circuit, $expectedIfaceId, $row->mac, $seenIfaceId, $type, $severity, $row->last_seen);
            $found++;
        }

        return ['anomalies' => $found];
    }
}
