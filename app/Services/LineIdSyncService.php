<?php

namespace App\Services;

use App\Domains\Access\Actions\DetectLineIdAnomaliesAction;
use App\Domains\Access\Actions\ReconcileLineIdsAction;
use App\Domains\Access\Kea\KeaControlClient;
use App\Domains\Access\Support\CircuitIdParser;
use App\Domains\Access\Support\SharedCircuitDetector;

/**
 * Fasáda pro zpětnou kompatibilitu — logika je v doméně Access
 * (app/Domains/Access). Nový kód volá rovnou akce/třídy domény; volající
 * se sem přepojí postupně a fasáda pak zanikne.
 *
 * Viz [[project_pppoe_wpa2_nis2]] fáze B. Voláno z `lineid:sync` (cron).
 */
class LineIdSyncService
{
    public function __construct(
        private ?ReconcileLineIdsAction $reconcile = null,
        private ?DetectLineIdAnomaliesAction $detect = null,
        private ?SharedCircuitDetector $shared = null,
        private ?KeaControlClient $kea = null,
        private ?CircuitIdParser $parser = null,
    ) {
        $this->reconcile ??= new ReconcileLineIdsAction();
        $this->detect    ??= new DetectLineIdAnomaliesAction();
        $this->shared    ??= new SharedCircuitDetector();
        $this->kea       ??= new KeaControlClient();
        $this->parser    ??= new CircuitIdParser();
    }

    /** @see ReconcileLineIdsAction::handle() */
    public function reconcileFromSeen(): array
    {
        return $this->reconcile->handle();
    }

    /** @see SharedCircuitDetector::isShared() */
    public function isSharedCircuit(string $circuit, ?string $circuitHex = null, ?string $remoteHex = null): bool
    {
        return $this->shared->isShared($circuit, $circuitHex, $remoteHex);
    }

    /** @see ReconcileLineIdsAction::pruneShared() */
    public function pruneSharedLineIds(): int
    {
        return $this->reconcile->pruneShared();
    }

    /** @see ReconcileLineIdsAction::backfillParse() */
    public function backfillParse(): int
    {
        return $this->reconcile->backfillParse();
    }

    /** @see DetectLineIdAnomaliesAction::handle() */
    public function detectAnomalies(int $recentDays = 7): array
    {
        return $this->detect->handle($recentDays);
    }

    /** @see KeaControlClient::flushHostCache() */
    public function flushKeaHostCache(): bool
    {
        return $this->kea->flushHostCache();
    }

    /** @see KeaControlClient::currentLeaseIp() */
    public function currentLeaseIp(string $mac): ?string
    {
        return $this->kea->currentLeaseIp($mac);
    }

    /** @see CircuitIdParser::decodeHex() */
    public function decodeHex(?string $hex): ?string
    {
        return $this->parser->decodeHex($hex);
    }

    /** @see CircuitIdParser::parse() */
    public function parseCircuitId(string $c): array
    {
        return $this->parser->parse($c);
    }
}
