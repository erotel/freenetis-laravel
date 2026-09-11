<?php

namespace App\Services;

use App\Models\LineId;
use App\Models\LineIdAnomaly;
use Illuminate\Support\Facades\DB;

/**
 * Self-learning IPoE line-id → iface mapování + MAC-anomaly detekce (fáze B).
 * RADIUS accounting plní staging `line_id_seen` (circuit-id hex + MAC) i u
 * statických leasů. Tato služba:
 *   - reconcileFromSeen(): KONZERVATIVNĚ páruje MAC → iface a překlápí do
 *     `line_ids` (nový port / stejný zákazník). Když by přemapovala existující
 *     port na JINÉHO zákazníka, NEUDĚLÁ to (konflikt = řeší detektor).
 *   - detectAnomalies(): porovná reálně viděnou MAC proti zavedenému mapování
 *     a registrované MAC → zapíše anomálie (přehození portů apod.).
 *
 * Viz [[project_pppoe_wpa2_nis2]] fáze B. Voláno z `lineid:sync` (cron).
 */
class LineIdSyncService
{
    /**
     * KONZERVATIVNÍ překlopení ze `line_id_seen` do `line_ids`.
     * @return array{reconciled:int, unmatched:int, conflicts:int}
     */
    public function reconcileFromSeen(): array
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
        // reboot/renew). Viz flushKeaHostCache() a [[project_lineid_tr101_gotcha]].
        $changed = $this->pruneSharedLineIds() > 0;

        $rows = DB::table('line_id_seen')->where('reconciled', 0)->get();
        foreach ($rows as $row) {
            $circuit = $this->decodeHex($row->circuit_id_hex);
            if ($circuit === null || $circuit === '') {
                continue;
            }
            // remote-id (ident switche/OLT) doplňuje identitu tam, kde circuit-id
            // sám nestačí (DCN na sdílené VLAN, TP-Link VLAN 1). Prázdný u Huawei
            // GPON/Vlanif/mikrotik → klíč = jen circuit-id (beze změny). Viz
            // [[project_lineid_tr101_gotcha]].
            $remote = $this->decodeHex($row->remote_id_hex) ?? '';

            $macIfaceId = $this->ifaceIdByMac($row->mac);
            if (!$macIfaceId) {
                $unmatched++; // neznámá MAC = onboarding kandidát, necháme reconciled=0
                continue;
            }

            // Sdílený relay/VLAN circuit-id (Vlanif = SVI L3 relaye, sdílí celá VLAN,
            // nebo circuit-id s víc MACy) NENÍ per-zákazník identita → neukládat do
            // line_ids; uklidit případný dřívější omyl. Bez toho falešné identity_cross.
            if ($this->isSharedCircuit($circuit, $row->circuit_id_hex, $row->remote_id_hex)) {
                $del = DB::table('line_ids')->where('circuit_id', $circuit)->where('remote_id', $remote)->delete();
                DB::table('line_id_seen')->where('id', $row->id)->update(['reconciled' => 1]);
                $changed = $changed || $del > 0; // smazaná rezervace → flush
                $shared++;
                continue;
            }

            $existing = DB::table('line_ids')->where('circuit_id', $circuit)->where('remote_id', $remote)->first();

            if ($existing === null) {
                // Nový port → vytvoř mapování.
                $p = $this->parseCircuitId($circuit);
                // device_ident: parser (Huawei „K364" apod.); u DCN/TP-Link, kde
                // parser dá null / jen hex, vezmi identitu switche z remote-id.
                $deviceIdent = $p['device_ident'];
                if ($remote !== '' && ($deviceIdent === null || str_starts_with((string) $deviceIdent, '0x'))) {
                    $deviceIdent = '0x' . strtoupper(bin2hex($remote));
                }
                LineId::create([
                    'circuit_id'   => $circuit,
                    'remote_id'    => $remote,
                    'iface_id'     => $macIfaceId,
                    'vendor'       => $p['vendor'],
                    'device_ident' => $deviceIdent,
                    'port'         => $p['port'],
                    'source'       => 'accounting',
                    'last_seen'    => $row->last_seen ?? now(),
                ]);
                DB::table('line_id_seen')->where('id', $row->id)->update(['reconciled' => 1]);
                $reconciled++;
                $changed = true; // nová rezervace → flush
            } elseif ((int) $existing->iface_id === $macIfaceId) {
                // Stejný zákazník na svém portu → refresh. Self-healing: když dřívější
                // (starší) parser nechal parse prázdný, teď ho doplň.
                $upd = ['last_seen' => $row->last_seen ?? now()];
                if ($existing->vendor === null && $existing->device_ident === null && $existing->port === null) {
                    $p = $this->parseCircuitId($circuit);
                    $upd['vendor']       = $p['vendor'];
                    $upd['device_ident'] = $p['device_ident'];
                    $upd['port']         = $p['port'];
                }
                DB::table('line_ids')->where('id', $existing->id)->update($upd);
                DB::table('line_id_seen')->where('id', $row->id)->update(['reconciled' => 1]);
                $reconciled++;
            } else {
                // KONFLIKT: na portu je MAC JINÉHO zákazníka → NEpřemapovat.
                // Necháme reconciled=0; detectAnomalies() to zapíše jako anomálii.
                $conflicts++;
            }
        }

        // Rezervace se změnily → pročisti Kea host-cache na všech uzlech, ať se
        // oprava projeví bez čekání na ruční zásah (jinak negative-cache verdikt
        // přežije reboot/renew zákazníka). Best-effort, nikdy neshodí sync.
        $flushed = null;
        if ($changed) {
            $flushed = $this->flushKeaHostCache();
        }

        return [
            'reconciled' => $reconciled, 'unmatched' => $unmatched,
            'conflicts' => $conflicts, 'shared' => $shared,
            'cache_flushed' => $flushed, // null = nebylo třeba, true/false = výsledek flushe
        ];
    }

    /**
     * Sdílený line-id = NENÍ per-zákazník identita: relay/VLAN circuit-id (Vlanif<N>
     * = SVI L3 relaye, sdílí ho celá VLAN) nebo circuit-id pozorovaný s víc MACy.
     * Takové ID se nesmí ukládat jako per-port mapování ani flagovat jako anomálie.
     */
    public function isSharedCircuit(string $circuit, ?string $circuitHex = null, ?string $remoteHex = null): bool
    {
        $p = $this->parseCircuitId($circuit);
        if ($p['port'] !== null && stripos($p['port'], 'Vlanif') === 0) {
            return true;
        }
        if ($circuitHex !== null) {
            // Počítej MAC per DVOJICE (circuit, remote) — dva switche se stejným
            // circuit-id ale jiným remote-id (DCN na sdílené VLAN, TP-Link) tak
            // NEjsou „shared"; víc MAC na téže dvojici = fakt sdílený port (bytovka).
            $macs = DB::table('line_id_seen')
                ->where('circuit_id_hex', $circuitHex)
                ->whereRaw('COALESCE(remote_id_hex, "") = COALESCE(?, "")', [$remoteHex])
                ->distinct()->count('mac');
            if ($macs > 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Odstraní z line_ids sdílené (VLAN/relay) záznamy, které tam nepatří — uložené
     * dřív (před klasifikací) nebo když se circuit-id stal sdíleným až 2. MACem po
     * uložení. Idempotentní. @return int počet odstraněných
     */
    public function pruneSharedLineIds(): int
    {
        $n = 0;
        foreach (DB::table('line_ids')->get(['id', 'circuit_id', 'remote_id']) as $l) {
            $hex = '0x' . bin2hex($l->circuit_id);
            $remoteHex = ($l->remote_id ?? '') !== '' ? '0x' . bin2hex($l->remote_id) : null;
            if ($this->isSharedCircuit($l->circuit_id, $hex, $remoteHex)) {
                DB::table('line_ids')->where('id', $l->id)->delete();
                $n++;
            }
        }
        return $n;
    }

    /**
     * Dorovná parse (vendor/device_ident/port) u záznamů line_ids, které ho mají
     * prázdný — typicky po vylepšení parseru o nový formát. Idempotentní.
     * @return int počet dorovnaných záznamů
     */
    public function backfillParse(): int
    {
        $n = 0;
        $rows = DB::table('line_ids')
            ->whereNull('vendor')->whereNull('device_ident')->whereNull('port')
            ->get(['id', 'circuit_id']);
        foreach ($rows as $r) {
            $p = $this->parseCircuitId($r->circuit_id);
            if ($p['vendor'] !== null || $p['device_ident'] !== null || $p['port'] !== null) {
                DB::table('line_ids')->where('id', $r->id)->update([
                    'vendor'       => $p['vendor'],
                    'device_ident' => $p['device_ident'],
                    'port'         => $p['port'],
                ]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * MAC-anomaly detekce nad nedávnými záznamy `line_id_seen`. Idempotentní
     * upsert do `line_id_anomalies`. Viz typy v migraci.
     * @return array{anomalies:int}
     */
    public function detectAnomalies(int $recentDays = 7): array
    {
        $found = 0;
        $since = now()->subDays($recentDays);

        $rows = DB::table('line_id_seen')->where('last_seen', '>=', $since)->get();
        foreach ($rows as $row) {
            $circuit = $this->decodeHex($row->circuit_id_hex);
            if ($circuit === null || $circuit === '') {
                continue;
            }
            $remote = $this->decodeHex($row->remote_id_hex) ?? '';

            // Sdílený relay/VLAN circuit-id (Vlanif / víc MACů na téže dvojici) není
            // per-zákazník identita → víc MACů je tam normální, NEflagovat.
            if ($this->isSharedCircuit($circuit, $row->circuit_id_hex, $row->remote_id_hex)) {
                continue;
            }

            $seenIfaceId     = $this->ifaceIdByMac($row->mac);
            $line            = DB::table('line_ids')->where('circuit_id', $circuit)->where('remote_id', $remote)->first();
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
                $home = DB::table('line_ids')->where('iface_id', $seenIfaceId)->first();
                if ($home && $home->circuit_id !== $circuit) {
                    $type = 'mac_moved';
                    $severity = 'high';
                }
            }

            if ($type === null) {
                continue; // OK nebo onboarding kandidát (neanomálie)
            }

            $this->upsertAnomaly($circuit, $expectedIfaceId, $row->mac, $seenIfaceId, $type, $severity, $row->last_seen);
            $found++;
        }

        return ['anomalies' => $found];
    }

    private function upsertAnomaly(
        string $circuit, ?int $expectedIfaceId, string $mac, ?int $seenIfaceId,
        string $type, string $severity, $lastSeen
    ): void {
        $now = $lastSeen ? \Carbon\Carbon::parse($lastSeen) : now();

        $a = LineIdAnomaly::firstOrNew(['circuit_id' => $circuit, 'seen_mac' => $mac]);
        if (!$a->exists) {
            $a->first_seen = $now;
            $a->seen_count = 0;
        }
        // Znovuotevření vyřešené anomálie jen když se objevila PO vyřešení
        // (přetrvávající přehození), jinak zůstane vyřešená.
        if ($a->resolved_at !== null && $now->greaterThan($a->resolved_at)) {
            $a->resolved_at = null;
        }
        $a->expected_iface_id = $expectedIfaceId;
        $a->seen_iface_id     = $seenIfaceId;
        $a->type              = $type;
        $a->severity          = $severity;
        $a->seen_count        = (int) $a->seen_count + 1;
        $a->last_seen         = $now;
        $a->save();
    }

    private function ifaceIdByMac(string $mac): ?int
    {
        $id = DB::table('ifaces')
            ->whereRaw("UPPER(REPLACE(mac,'-',':')) = UPPER(?)", [$mac])
            ->value('id');
        return $id ? (int) $id : null;
    }

    /**
     * Plošný `cache-clear` host-cache na všech Kea uzlech (best-effort). Nutné po
     * změně rezervací: Kea si cachuje výsledek RADIUS lookupu a špatný/negativní
     * verdikt z okna cutoveru jinak přežije reboot i renew zákazníka (reboot ani
     * renew to neopraví, dokud se cache nevyčistí). Viz [[project_lineid_tr101_gotcha]].
     *
     * Produkce (Kea na samostatných uzlech): HTTP control API (basic auth) na každý
     * endpoint z `kea.control_nodes`. PoC/dev (Kea lokálně): fallback na unix socket
     * `kea.control_socket`. Nikdy nehodí výjimku (sync nesmí spadnout kvůli nedostupné
     * Kee). Vrací true jen když VŠECHNY oslovené uzly potvrdily „result: 0".
     * POZN.: cache-clear je plošný (donutí re-query všech rezervací) — přijatelné,
     * běží jen při reálné změně rezervací, ne na každý paket.
     */
    public function flushKeaHostCache(): bool
    {
        $nodes = (array) config('kea.control_nodes', []);
        if ($nodes !== []) {
            $ok = true;
            foreach ($nodes as $base) {
                $ok = $this->flushViaHttp((string) $base) && $ok;
            }
            return $ok;
        }

        // Fallback: lokální unix socket (Kea na stejném hostu jako FreenetIS).
        $sock = (string) config('kea.control_socket', '');
        if ($sock === '' || !@file_exists($sock)) {
            return false;
        }
        try {
            $client = @stream_socket_client('unix://' . $sock, $errno, $errstr, 2);
            if (!$client) {
                return false;
            }
            @fwrite($client, json_encode(['command' => 'cache-clear']));
            @stream_socket_shutdown($client, STREAM_SHUT_WR); // EOF → Kea zpracuje příkaz
            $resp = (string) @stream_get_contents($client, 4096);
            @fclose($client);
            return str_contains($resp, '"result": 0');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Vrátí aktuální aktivní Kea v4 lease IP pro danou MAC, nebo null. Čte přes HTTP
     * control API (`lease4-get-by-hw-address`, hook lease_cmds) na `kea.control_nodes`,
     * bere první aktivní (state 0) lease. Best-effort, nikdy nehodí výjimku.
     *
     * Použití: při registraci přípojky převzít IP, kterou zákazník dostal z poolu, a
     * zapsat ji jako jeho fixní IPv4 → IP se mu po registraci nezmění. Viz
     * [[project_lineid_tr101_gotcha]]. Vyžaduje nastavené kea.control_nodes (produkce).
     */
    public function currentLeaseIp(string $mac): ?string
    {
        $mac = strtolower(trim($mac));
        if (!preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $mac)) {
            return null; // jen validní MAC (ETHERNET iface); jiné typy leasy nemají
        }
        foreach ((array) config('kea.control_nodes', []) as $base) {
            $resp = $this->keaHttpCommand((string) $base, [
                'command'   => 'lease4-get-by-hw-address',
                'arguments' => ['hw-address' => $mac],
            ]);
            foreach ($resp['arguments']['leases'] ?? [] as $l) {
                if ((int) ($l['state'] ?? 0) === 0 && !empty($l['ip-address'])) {
                    return (string) $l['ip-address']; // state 0 = aktivní
                }
            }
        }
        return null;
    }

    /** Pošle příkaz na Kea HTTP control endpoint, vrátí dekódovanou odpověď nebo null. */
    private function keaHttpCommand(string $base, array $cmd): ?array
    {
        $base = rtrim($base, '/');
        if ($base === '') {
            return null;
        }
        $timeout = max(1, (int) config('kea.control_timeout', 3));
        try {
            $ch = curl_init($base . '/');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode($cmd),
                CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
                CURLOPT_USERPWD        => (string) config('kea.control_user', '') . ':' . (string) config('kea.control_password', ''),
            ]);
            $resp = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200) {
                return null;
            }
            $json = json_decode($resp, true);
            // Přímý kea-dhcp4 HTTP socket vrací objekt {result,arguments}; ctrl-agent pole [{...}].
            if (isset($json[0]) && is_array($json[0])) {
                $json = $json[0];
            }
            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Pošle `cache-clear` na jeden Kea HTTP control endpoint (basic auth). Best-effort. */
    private function flushViaHttp(string $base): bool
    {
        $base = rtrim($base, '/');
        if ($base === '') {
            return false;
        }
        $user = (string) config('kea.control_user', '');
        $pass = (string) config('kea.control_password', '');
        $timeout = max(1, (int) config('kea.control_timeout', 3));
        try {
            $ch = curl_init($base . '/');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode(['command' => 'cache-clear']),
                CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
                CURLOPT_USERPWD        => $user . ':' . $pass,
            ]);
            $resp = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code === 200 && str_contains($resp, '"result": 0');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** '0x4769..' / '4769..' → ASCII řetězec, nebo null. */
    public function decodeHex(?string $hex): ?string
    {
        if (!$hex) {
            return null;
        }
        $hex = preg_replace('/^0x/i', '', trim($hex));
        if ($hex === '' || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) {
            return null;
        }
        $bin = @hex2bin($hex);
        return $bin === false ? null : $bin;
    }

    /**
     * Rozparsuje circuit-id na vendor / identitu prvku / port. Best-effort pro
     * audit a čitelnost; RADIUS lookup jede na SYROVÉM circuit_id, takže na
     * přesnosti parseru přidělení IP nezávisí. 4 formáty:
     *   Huawei   `GigabitEthernet0/0/12:339.0 K364/0/0/0/0/0`
     *   Huawei   `0180.0000.c88d-833a-7770:Vlanif180` (VLAN-if identita, ne fyz. port)
     *   DCN      `Vlan325+Ethernet1/0/13`
     *   GPON     `F47960E73E46 xpon 0/2/0/8:38.1.1` (ident OLT + xpon cesta vč. ONT)
     *   MikroTik `Smer9 eth 0/4`
     * Neznámý formát → vendor 'unknown', celý řetězec do device_ident (nikdy vše NULL).
     * @return array{vendor:?string, device_ident:?string, port:?string}
     */
    public function parseCircuitId(string $c): array
    {
        // Binární option82 circuit-id (TP-Link SG2008 apod. per-port default:
        // 0x0004 <slot16> <port16>) detekuj PŘED trim() — trim by sežral vedoucí
        // \x00. Poznáme podle ŘÍDICÍCH/null bajtů (ne podle vysokých — akcentovaný
        // UTF-8 text je legitimní). Matching jede na SYROVÉM circuit_id, tohle je
        // jen čitelný extrakt do UI. Viz [[project_lineid_tr101_gotcha]].
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $c)) {
            $hex = strtoupper(bin2hex($c));
            if (strlen($c) === 6 && substr($hex, 0, 4) === '0004') {
                return ['vendor' => 'tplink', 'device_ident' => '0x' . $hex, 'port' => 'port ' . hexdec(substr($hex, 8, 4))];
            }
            return ['vendor' => 'unknown', 'device_ident' => '0x' . $hex, 'port' => null];
        }
        $c = trim($c);

        // GPON (Huawei xpon): "<OLT-ident> xpon <frame/slot/port[/ont]>:<ont.gem.vlan>"
        // device_ident = ident OLT (prefix před "xpon") → odliší víc OLT za stejným
        // DHCP serverem (10.133.0.16); port = celá xpon cesta vč. ONT → unikátní per
        // přípojka i na stejném PON portu. Matching stejně jede na SYROVÉM circuit_id,
        // tohle je jen pro čitelnost UI, takže granularitu můžeme brát maximální.
        if (preg_match('~^(.*?)\s*\bxpon\s+(\S+)~i', $c, $m)) {
            $ident = trim($m[1]);
            return ['vendor' => 'gpon', 'device_ident' => $ident !== '' ? $ident : null, 'port' => 'xpon ' . $m[2]];
        }

        // Huawei: "<port>:<vlan>.<x> <hostname>[/...]"
        if (preg_match('~^(\S+):(\d+)\.\S+\s+(\S+?)(?:/.*)?$~', $c, $m)) {
            return ['vendor' => 'huawei', 'device_ident' => $m[3], 'port' => $m[1]];
        }

        // Huawei (VLAN-if): "<switch-ident>:Vlanif<id>"  – port je VLAN interface,
        // ne fyzický port (hrubší granularita: swap se detekuje na úrovni VLANu).
        if (preg_match('~^(.+):(Vlanif\d+)$~i', $c, $m)) {
            return ['vendor' => 'huawei', 'device_ident' => $m[1], 'port' => $m[2]];
        }

        // DCN: "Vlan<id>+<port>"  (device_ident = remote-id switch MAC, sem nedáme)
        if (preg_match('~^Vlan(\d+)\+(.+)$~i', $c, $m)) {
            return ['vendor' => 'dcn', 'device_ident' => null, 'port' => $m[2]];
        }

        // MikroTik: "<identity> eth <port>"  /  "<identity> <ifname>"
        if (preg_match('~^(\S+)\s+(eth\s+\S+|ether\S+)$~i', $c, $m)) {
            return ['vendor' => 'mikrotik', 'device_ident' => $m[1], 'port' => $m[2]];
        }

        // Neznámý formát: best-effort split, ať UI ukáže něco čitelného (ne vše NULL).
        // Rozdělíme na posledním ":" (identita:port); jinak celý řetězec = device_ident.
        if (preg_match('~^(.+):([^:]+)$~', $c, $m)) {
            return ['vendor' => 'unknown', 'device_ident' => trim($m[1]), 'port' => trim($m[2])];
        }
        return ['vendor' => 'unknown', 'device_ident' => $c !== '' ? $c : null, 'port' => null];
    }
}
