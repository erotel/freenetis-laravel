<?php

namespace App\Services;

use App\Models\LineId;
use Illuminate\Support\Facades\DB;

/**
 * Self-learning IPoE line-id → iface mapování (fáze B). RADIUS accounting plní
 * staging `line_id_seen` (circuit-id hex + MAC) i u statických leasů. Tato služba
 * páruje MAC → iface a překlápí známé do `line_ids`; nespárované zůstávají ve
 * staging (reconciled=0) jako kandidáti na onboarding.
 *
 * Viz [[project_pppoe_wpa2_nis2]] fáze B. Voláno z `lineid:sync` (cron).
 */
class LineIdSyncService
{
    /**
     * Překlopí nespárované záznamy ze `line_id_seen` do `line_ids`.
     * @return array{reconciled:int, unmatched:int}
     */
    public function reconcileFromSeen(): array
    {
        $reconciled = 0;
        $unmatched  = 0;

        $rows = DB::table('line_id_seen')->where('reconciled', 0)->get();
        foreach ($rows as $row) {
            $circuit = $this->decodeHex($row->circuit_id_hex);
            if ($circuit === null || $circuit === '') {
                continue;
            }

            $ifaceId = DB::table('ifaces')
                ->whereRaw("UPPER(REPLACE(mac,'-',':')) = UPPER(?)", [$row->mac])
                ->value('id');

            if (!$ifaceId) {
                $unmatched++;
                continue; // neznámý MAC = onboarding kandidát, necháme reconciled=0
            }

            $parsed = $this->parseCircuitId($circuit);

            LineId::updateOrCreate(
                ['circuit_id' => $circuit],
                [
                    'iface_id'     => $ifaceId,
                    'vendor'       => $parsed['vendor'],
                    'device_ident' => $parsed['device_ident'],
                    'port'         => $parsed['port'],
                    'source'       => 'accounting',
                    'last_seen'    => $row->last_seen ?? now(),
                ]
            );

            DB::table('line_id_seen')->where('id', $row->id)->update(['reconciled' => 1]);
            $reconciled++;
        }

        return ['reconciled' => $reconciled, 'unmatched' => $unmatched];
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
