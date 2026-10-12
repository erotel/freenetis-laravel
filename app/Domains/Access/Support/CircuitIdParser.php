<?php

namespace App\Domains\Access\Support;

/**
 * Dekódování a rozparsování option82 circuit-id. Čisté funkce bez DB — matching
 * v RADIUSu jede na SYROVÉM circuit_id, parse slouží pro audit/UI.
 */
class CircuitIdParser
{
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
    public function parse(string $c): array
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
