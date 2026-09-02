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

        $rows = DB::table('line_id_seen')->where('reconciled', 0)->get();
        foreach ($rows as $row) {
            $circuit = $this->decodeHex($row->circuit_id_hex);
            if ($circuit === null || $circuit === '') {
                continue;
            }

            $macIfaceId = $this->ifaceIdByMac($row->mac);
            if (!$macIfaceId) {
                $unmatched++; // neznámá MAC = onboarding kandidát, necháme reconciled=0
                continue;
            }

            $existing = DB::table('line_ids')->where('circuit_id', $circuit)->first();

            if ($existing === null) {
                // Nový port → vytvoř mapování.
                $p = $this->parseCircuitId($circuit);
                LineId::create([
                    'circuit_id'   => $circuit,
                    'iface_id'     => $macIfaceId,
                    'vendor'       => $p['vendor'],
                    'device_ident' => $p['device_ident'],
                    'port'         => $p['port'],
                    'source'       => 'accounting',
                    'last_seen'    => $row->last_seen ?? now(),
                ]);
                DB::table('line_id_seen')->where('id', $row->id)->update(['reconciled' => 1]);
                $reconciled++;
            } elseif ((int) $existing->iface_id === $macIfaceId) {
                // Stejný zákazník na svém portu → jen refresh.
                DB::table('line_ids')->where('id', $existing->id)->update(['last_seen' => $row->last_seen ?? now()]);
                DB::table('line_id_seen')->where('id', $row->id)->update(['reconciled' => 1]);
                $reconciled++;
            } else {
                // KONFLIKT: na portu je MAC JINÉHO zákazníka → NEpřemapovat.
                // Necháme reconciled=0; detectAnomalies() to zapíše jako anomálii.
                $conflicts++;
            }
        }

        return ['reconciled' => $reconciled, 'unmatched' => $unmatched, 'conflicts' => $conflicts];
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

            $seenIfaceId     = $this->ifaceIdByMac($row->mac);
            $line            = DB::table('line_ids')->where('circuit_id', $circuit)->first();
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
     *   DCN      `Vlan325+Ethernet1/0/13`
     *   GPON     `... xpon 0/2/0/8 ...`
     *   MikroTik `Smer9 eth 0/4`
     * @return array{vendor:?string, device_ident:?string, port:?string}
     */
    public function parseCircuitId(string $c): array
    {
        $c = trim($c);

        // GPON: obsahuje "xpon <frame/slot/pon/ont>"
        if (preg_match('~xpon\s+([\d/]+)~i', $c, $m)) {
            return ['vendor' => 'gpon', 'device_ident' => null, 'port' => 'xpon ' . $m[1]];
        }

        // Huawei: "<port>:<vlan>.<x> <hostname>[/...]"
        if (preg_match('~^(\S+):(\d+)\.\S+\s+(\S+?)(?:/.*)?$~', $c, $m)) {
            return ['vendor' => 'huawei', 'device_ident' => $m[3], 'port' => $m[1]];
        }

        // DCN: "Vlan<id>+<port>"  (device_ident = remote-id switch MAC, sem nedáme)
        if (preg_match('~^Vlan(\d+)\+(.+)$~i', $c, $m)) {
            return ['vendor' => 'dcn', 'device_ident' => null, 'port' => $m[2]];
        }

        // MikroTik: "<identity> eth <port>"  /  "<identity> <ifname>"
        if (preg_match('~^(\S+)\s+(eth\s+\S+|ether\S+)$~i', $c, $m)) {
            return ['vendor' => 'mikrotik', 'device_ident' => $m[1], 'port' => $m[2]];
        }

        return ['vendor' => null, 'device_ident' => null, 'port' => null];
    }
}
