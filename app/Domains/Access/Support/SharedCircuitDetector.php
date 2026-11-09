<?php

namespace App\Domains\Access\Support;

use App\Domains\Access\Repositories\LineIdRepository;

/**
 * Sdílený line-id = NENÍ per-zákazník identita: relay/VLAN circuit-id (Vlanif<N>
 * = SVI L3 relaye, sdílí ho celá VLAN) nebo circuit-id pozorovaný s víc MACy.
 * Takové ID se nesmí ukládat jako per-port mapování ani flagovat jako anomálie.
 */
class SharedCircuitDetector
{
    public function __construct(
        private CircuitIdParser $parser = new CircuitIdParser(),
        private LineIdRepository $lines = new LineIdRepository(),
    ) {
    }

    /**
     * Sdílený z podstaty: relay/VLAN circuit-id (Vlanif<N>) — identita celé VLAN,
     * nikdy jednoho zákazníka. Jen u takového smí sync mapování smazat.
     */
    public function isStructural(string $circuit): bool
    {
        $p = $this->parser->parse($circuit);
        return $p['port'] !== null && stripos($p['port'], 'Vlanif') === 0;
    }

    /**
     * Sdílený strukturálně NEBO víc MAC na téže dvojici (circuit, remote). Víc MAC
     * ale znamená i obyčejnou výměnu routeru — takový port se proto nesmí z line_ids
     * mazat (přišel by o rezervaci, viz incident 2026-11-09 K364 Gi0/0/12), jen
     * se pro něj nevytváří nové mapování automaticky.
     */
    public function isShared(string $circuit, ?string $circuitHex = null, ?string $remoteHex = null): bool
    {
        if ($this->isStructural($circuit)) {
            return true;
        }
        if ($circuitHex !== null) {
            // Počítej MAC per DVOJICE (circuit, remote) — dva switche se stejným
            // circuit-id ale jiným remote-id (DCN na sdílené VLAN, TP-Link) tak
            // NEjsou „shared"; víc MAC na téže dvojici = fakt sdílený port (bytovka).
            if ($this->lines->distinctMacCount($circuitHex, $remoteHex) > 1) {
                return true;
            }
        }
        return false;
    }
}
