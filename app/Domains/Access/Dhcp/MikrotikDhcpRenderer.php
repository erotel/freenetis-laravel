<?php

namespace App\Domains\Access\Dhcp;

use App\Models\Setting;

/**
 * RouterOS skript DHCP konfigurace (plný export / jen leasy) z pole serverů
 * od DhcpServerBuilder. MK ho importuje → `remove [find]` + re-add, pool a PPPoE
 * profil/server se upsertují (ruční ladění se nepřepisuje).
 */
class MikrotikDhcpRenderer
{
    /**
     * @param ?string $relayInterface  Když je zadané (zařízení je v dhcp_relay_map),
     *                                  export běží v relay režimu: interface=<toto>
     *                                  + relay=<gateway subnetu> místo lokálního iface.
     * @param string  $role            'primary' = plný server (authoritative=yes,
     *                                  vlastní pool); 'static' = druhý server v páru
     *                                  (authoritative=after-10sec-delay, static-only).
     */
    public function renderFull(array $servers, ?string $relayInterface = null, string $role = 'primary', bool $forceRadius = false, bool $forceTr101 = false): string
    {
        // Setting::get vrací uloženou hodnotu — když admin v UI uloží prázdné
        // pole, vrátí '' a default '10800' v get() se neuplatní. Cast na int
        // pak udělá 0 → lease-time=00:00:00. 3 h fallback bereme i pro 0.
        $leaseSeconds = (int) Setting::get('dhcp_lease_time', '10800');
        if ($leaseSeconds <= 0) $leaseSeconds = 10800;
        $leaseTime    = sprintf('%02d:%02d:%02d', intdiv($leaseSeconds, 3600), intdiv($leaseSeconds % 3600, 60), $leaseSeconds % 60);

        $isStatic = $relayInterface !== null && $role === 'static';

        $out = '';
        // Pool NEmazat přes `remove [find]` — smazal by i pool, na který se PPP
        // profil váže interním ID (remote-address=*5E). Po remove+add dostane
        // pool nové ID → PPP profil ztratí pool (sdílený DHCP+PPPoE pool). Upsert
        // podle JMÉNA: existující přepíšeme v místě (`set` → ID zůstane → PPP
        // binding drží), chybějící přidáme. DHCP-server pool referuje jménem,
        // tomu ID nevadí. Trade-off: pooly zrušených subnetů se už neuklidí
        // hromadně (osiřelý pool bez referujícího dhcp-serveru/profilu je inertní).
        foreach ($servers as $s) {
            // Fragmentovaný pool (víc rozsahů s dírami po registrovaných statikách).
            // Fallback na souvislý rozsah jen když je pool celý zaplněný (prázdné
            // $ranges) — to udrží DHCP funkční (statiku i tak chrání MAC lease).
            $rangesStr = !empty($s['ranges'])
                ? implode(',', $s['ranges'])
                : "{$s['range_start']}-{$s['range_end']}";
            $nm = self::quote($s['name']);
            $out .= ":if ([:len [/ip pool find name=\"{$nm}\"]]=0)"
                  . " do={/ip pool add name=\"{$nm}\" ranges={$rangesStr}}"
                  . " else={/ip pool set [/ip pool find name=\"{$nm}\"] ranges={$rangesStr}}\r\n";
        }
        $out .= "/ip dhcp-server\r\nremove [find]\r\n";
        foreach ($servers as $s) {
            $nm = self::quote($s['name']);
            // use-radius: ipoe subnet (RADIUS Framed-IP podle line-id) NEBO MK trvale
            // vynucený v dhcp_force_radius (optické brány jedou přes RADIUS i než se
            // subnet přepne na ipoe). tr101: jen MK v dhcp_force_tr101 (bridge/direct
            // režim jinak nepředá option82 circuit-id do RADIUSu; v relay režimu netřeba).
            // Bez tohoto by remove[find]+re-add při načtení configu ruční nastavení smazal.
            // Viz [[project_lineid_tr101_gotcha]].
            $useRadius = (!empty($s['ipoe']) || $forceRadius) ? ' use-radius=yes' : '';
            $tr101     = $forceTr101 ? ' support-broadband-tr101=yes' : '';
            if ($relayInterface !== null) {
                // Relay režim: matchujeme podle relay=<gateway> (giaddr), interface
                // je společné relay rozhraní (např. vlan1010), ne lokální iface.
                $iface = self::quote(self::ascii($relayInterface));
                if ($isStatic) {
                    $out .= "add name=\"{$nm}\" authoritative=after-10sec-delay bootp-support=static disabled=no interface=\"{$iface}\" relay={$s['gateway']} lease-time={$leaseTime} address-pool=static-only{$useRadius}{$tr101}\r\n";
                } else {
                    $out .= "add name=\"{$nm}\" address-pool=\"{$nm}\" authoritative=yes bootp-support=static disabled=no interface=\"{$iface}\" relay={$s['gateway']} lease-time={$leaseTime}{$useRadius}{$tr101}\r\n";
                }
            } else {
                $out .= "add name=\"{$nm}\" address-pool=\"{$nm}\" authoritative=after-2sec-delay bootp-support=static disabled=no interface=\"" . self::quote($s['interface']) . "\" lease-time={$leaseTime}{$useRadius}{$tr101}\r\n";
            }
        }
        $out .= "/ip dhcp-server network\r\nremove [find]\r\n";
        foreach ($servers as $s) {
            $dns = implode(',', $s['dns_servers']);
            $out .= "add address={$s['cidr']} dhcp-option=\"\" dns-server={$dns} gateway={$s['gateway']} ntp-server=\"\" wins-server=\"\"\r\n";
        }
        $out .= "/ip dhcp-server lease\r\nremove [find]\r\n";
        foreach ($servers as $s) {
            // IPoE subnet: statické leasy NEgenerujeme — se statickým leasem by se
            // MK RADIUSu neptal (použil by lokální IP), takže IP přiděluje RADIUS
            // podle line-id. Viz fáze B.
            if (!empty($s['ipoe'])) continue;
            foreach ($s['hosts'] as $h) {
                $out .= "add address={$h['ip_address']} disabled=no mac-address={$h['mac']} server=\"" . self::quote($h['server']) . "\" comment=\"" . self::comment($h['comment']) . "\"\r\n";
            }
        }

        // PPP profil + pppoe-server (jen se zapnutým PPPoE, ne v relay režimu —
        // relay MK není zákaznická brána). Add-if-missing: existující (ručně
        // laděné) profily/servery NEpřepisujeme, jen doplníme chybějící →
        // žádné mazání, žádné clobbernutí. RADIUS klient + `/ppp aaa use-radius`
        // zůstávají ruční infra per-MK (mimo rozsah exportu); registrovaným
        // zákazníkům dává IP RADIUS Framed-IP, remote-address=pool je jen
        // bootstrap fallback (admin/admin → captive). Profil: name/remote-address
        // = název subnetu (=sdílený pool), local-address = brána, dns z nastavení,
        // change-tcp-mss=yes (PPPoE 1492 → MSS clamp).
        if (Setting::get('pppoe_enabled', 0) && $relayInterface === null) {
            $serviceName = self::quote(self::ascii((string) Setting::get('pppoe_service_name', 'service1')));
            $seenIfaces  = [];
            foreach ($servers as $s) {
                $nm  = self::quote($s['name']);
                $dns = implode(',', $s['dns_servers']);
                $out .= ":if ([:len [/ppp profile find name=\"{$nm}\"]]=0)"
                      . " do={/ppp profile add name=\"{$nm}\" local-address={$s['gateway']} remote-address=\"{$nm}\" dns-server={$dns} use-ipv6=yes change-tcp-mss=yes}\r\n";

                // pppoe-server: max 1 na rozhraní (víc subnetů na jednom iface →
                // první profil jako default; RADIUS stejně přepíše IP per-user).
                $iface = self::quote($s['interface']);
                if (isset($seenIfaces[$iface])) continue;
                $seenIfaces[$iface] = true;
                $out .= ":if ([:len [/interface pppoe-server server find interface=\"{$iface}\"]]=0)"
                      . " do={/interface pppoe-server server add service-name=\"{$serviceName}\" interface=\"{$iface}\" default-profile=\"{$nm}\" disabled=no one-session-per-host=yes authentication=pap,chap,mschap2}\r\n";
            }
        }

        return $out;
    }

    public function renderLeaseOnly(array $servers): string
    {
        $out = "/ip dhcp-server lease\r\n";
        foreach ($servers as $s) {
            $out .= "remove [find server=\"" . self::quote($s['name']) . "\"]\r\n";
            // IPoE subnet: bez statických leasů (IP z RADIUSu podle line-id).
            if (!empty($s['ipoe'])) continue;
            foreach ($s['hosts'] as $h) {
                $out .= "add address={$h['ip_address']} disabled=no mac-address={$h['mac']} server=\"" . self::quote($h['server']) . "\" comment=\"" . self::comment($h['comment']) . "\"\r\n";
            }
        }
        return $out;
    }

    public static function ascii(string $s): string
    {
        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    }

    /**
     * Hodnota do RouterOS řetězce v uvozovkách: escapuje \ " $ ? a řídicí znaky
     * (nový řádek by ukončil příkaz) nahradí mezerou — jinak by název/jméno
     * vložilo vlastní příkaz do importovaného skriptu.
     */
    public static function quote(string $s): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]/', ' ', $s);
        return strtr($s, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$', '?' => '\\?']);
    }

    /** Volný popisek (komentář leasu): navíc bez [] {} — závorky jen kulaté. */
    public static function comment(string $s): string
    {
        return self::quote(strtr($s, ['[' => '(', ']' => ')', '{' => '(', '}' => ')']));
    }
}
