<?php

namespace App\Domains\Access\Kea;

/**
 * Kea DHCP control API (HTTP basic auth na `kea.control_nodes`, fallback unix
 * socket `kea.control_socket`). Vše best-effort — nikdy nehodí výjimku.
 */
class KeaControlClient
{
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
    public function flushHostCache(): bool
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
}
