<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Events\IpoeReservationsChanged;
use App\Domains\Access\Repositories\LineIdRepository;
use App\Domains\Access\Support\CircuitIdParser;
use App\Domains\Access\Support\SharedCircuitDetector;

/**
 * Self-learning IPoE line-id: KONZERVATIVNÍ překlopení `line_id_seen` → `line_ids`
 * (nový port / stejný zákazník). Když by přemapovalo existující port na JINÉHO
 * zákazníka, NEUDĚLÁ to (konflikt řeší {@see DetectLineIdAnomaliesAction}).
 * Při změně rezervací dispatchne IpoeReservationsChanged (→ flush Kea host-cache).
 * Voláno z `lineid:sync` (cron).
 */
class ReconcileLineIdsAction
{
    public function __construct(
        private LineIdRepository $lines = new LineIdRepository(),
        private CircuitIdParser $parser = new CircuitIdParser(),
        private SharedCircuitDetector $shared = new SharedCircuitDetector(),
    ) {
    }

    /** @return array{reconciled:int, unmatched:int, conflicts:int, shared:int, cache_flushed:?bool} */
    public function handle(): array
    {
        $reconciled = 0;
        $unmatched  = 0;
        $conflicts  = 0;
        $shared     = 0;

        // Self-healing: dorovnej parse u existujících záznamů s prázdným parsem
        // (např. po vylepšení parseru) — nezávisle na reconciled flagu.
        $this->backfillParse();
        // Self-healing: vyházej sdílené (VLAN/relay) záznamy, které do line_ids nepatří
        // (uložené dřív, nebo se staly sdílené až 2. MACem po uložení).
        // Změnu rezervací (vznik/smazání line_id nebo prune) si pamatujeme, ať na
        // konci pošleme Kee cache-clear (jinak špatný negative-cache verdikt přežije
        // reboot/renew). Viz [[project_lineid_tr101_gotcha]].
        $changed = $this->pruneShared() > 0;

        foreach ($this->lines->unreconciledSeen() as $row) {
            $circuit = $this->parser->decodeHex($row->circuit_id_hex);
            if ($circuit === null || $circuit === '') {
                continue;
            }
            // remote-id (ident switche/OLT) doplňuje identitu tam, kde circuit-id
            // sám nestačí (DCN na sdílené VLAN, TP-Link VLAN 1). Prázdný u Huawei
            // GPON/Vlanif/mikrotik → klíč = jen circuit-id.
            $remote = $this->parser->decodeHex($row->remote_id_hex) ?? '';

            $macIfaceId = $this->lines->ifaceIdByMac($row->mac);
            if (!$macIfaceId) {
                $unmatched++; // neznámá MAC = onboarding kandidát, necháme reconciled=0
                continue;
            }

            // Sdílený relay/VLAN circuit-id NENÍ per-zákazník identita → neukládat
            // do line_ids; uklidit případný dřívější omyl. Bez toho falešné identity_cross.
            if ($this->shared->isShared($circuit, $row->circuit_id_hex, $row->remote_id_hex)) {
                $del = $this->lines->delete($circuit, $remote);
                $this->lines->markSeenReconciled($row->id);
                $changed = $changed || $del > 0; // smazaná rezervace → flush
                $shared++;
                continue;
            }

            $existing = $this->lines->find($circuit, $remote);

            if ($existing === null) {
                // Nový port → vytvoř mapování.
                $p = $this->parser->parse($circuit);
                // device_ident: parser (Huawei „K364" apod.); u DCN/TP-Link, kde
                // parser dá null / jen hex, vezmi identitu switche z remote-id.
                $deviceIdent = $p['device_ident'];
                if ($remote !== '' && ($deviceIdent === null || str_starts_with((string) $deviceIdent, '0x'))) {
                    $deviceIdent = '0x' . strtoupper(bin2hex($remote));
                }
                $this->lines->create([
                    'circuit_id'   => $circuit,
                    'remote_id'    => $remote,
                    'iface_id'     => $macIfaceId,
                    'vendor'       => $p['vendor'],
                    'device_ident' => $deviceIdent,
                    'port'         => $p['port'],
                    'source'       => 'accounting',
                    'last_seen'    => $row->last_seen ?? now(),
                ]);
                $this->lines->markSeenReconciled($row->id);
                $reconciled++;
                $changed = true; // nová rezervace → flush
            } elseif ((int) $existing->iface_id === $macIfaceId) {
                // Stejný zákazník na svém portu → refresh. Self-healing: když dřívější
                // (starší) parser nechal parse prázdný, teď ho doplň.
                $upd = ['last_seen' => $row->last_seen ?? now()];
                if ($existing->vendor === null && $existing->device_ident === null && $existing->port === null) {
                    $p = $this->parser->parse($circuit);
                    $upd['vendor']       = $p['vendor'];
                    $upd['device_ident'] = $p['device_ident'];
                    $upd['port']         = $p['port'];
                }
                $this->lines->update($existing->id, $upd);
                $this->lines->markSeenReconciled($row->id);
                $reconciled++;
            } else {
                // KONFLIKT: na portu je MAC JINÉHO zákazníka → NEpřemapovat.
                // Necháme reconciled=0; detektor anomálií to zapíše.
                $conflicts++;
            }
        }

        // Rezervace se změnily → event (listener pročistí Kea host-cache na všech
        // uzlech), ať se oprava projeví bez ručního zásahu. Best-effort, nikdy
        // neshodí sync. Výsledek flushe nese event (pro výpis lineid:sync).
        $flushed = null;
        if ($changed) {
            $event = new IpoeReservationsChanged('lineid:sync');
            event($event);
            $flushed = $event->cacheFlushed;
        }

        return [
            'reconciled' => $reconciled, 'unmatched' => $unmatched,
            'conflicts' => $conflicts, 'shared' => $shared,
            'cache_flushed' => $flushed, // null = nebylo třeba, true/false = výsledek flushe
        ];
    }

    /**
     * Odstraní z line_ids sdílené (VLAN/relay) záznamy — uložené dřív (před
     * klasifikací) nebo když se circuit-id stal sdíleným až 2. MACem po uložení.
     * Idempotentní. @return int počet odstraněných
     */
    public function pruneShared(): int
    {
        $n = 0;
        foreach ($this->lines->allIdentities() as $l) {
            $hex = '0x' . bin2hex($l->circuit_id);
            $remoteHex = ($l->remote_id ?? '') !== '' ? '0x' . bin2hex($l->remote_id) : null;
            if ($this->shared->isShared($l->circuit_id, $hex, $remoteHex)) {
                $this->lines->deleteById($l->id);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Dorovná parse (vendor/device_ident/port) u záznamů s prázdným parsem —
     * typicky po vylepšení parseru o nový formát. Idempotentní.
     * @return int počet dorovnaných záznamů
     */
    public function backfillParse(): int
    {
        $n = 0;
        foreach ($this->lines->withoutParse() as $r) {
            $p = $this->parser->parse($r->circuit_id);
            if ($p['vendor'] !== null || $p['device_ident'] !== null || $p['port'] !== null) {
                $this->lines->update($r->id, [
                    'vendor'       => $p['vendor'],
                    'device_ident' => $p['device_ident'],
                    'port'         => $p['port'],
                ]);
                $n++;
            }
        }
        return $n;
    }
}
