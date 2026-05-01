<?php

namespace App\Services;

use App\Models\GponOlt;
use App\Models\Ont;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GponService
{
    private string $secLevel = 'authPriv';

    private const OID_ONT_SERIALS = '1.3.6.1.4.1.2011.6.128.1.1.2.48.1.2';
    private const OID_IF_NAME     = '1.3.6.1.2.1.31.1.1.1.1';

    // ── Veřejné metody ──────────────────────────────────────────────────────

    public function getOltConfig(string $ip): GponOlt
    {
        $olt = GponOlt::where('ip', $ip)->first();
        if (!$olt) {
            throw new RuntimeException("OLT {$ip} nebyl nalezen v konfiguraci.");
        }
        return $olt;
    }

    /**
     * Skenuje všechny OLT z gpon_olts tabulky a ukládá nové ONT.
     */
    public function scanNewOnts(): int
    {
        $olts     = GponOlt::all();
        $countNew = 0;

        foreach ($olts as $olt) {
            $countNew += $this->scanOlt($olt);
        }

        return $countNew;
    }

    /**
     * Registruje ONT na OLT podle ID záznamu v DB.
     */
    public function registerOntById(int $id, ?string $houseNo = null, ?string $userName = null): void
    {
        $ont      = Ont::findOrFail($id);
        $houseNo  = $houseNo ?? '';
        $userName = $userName ?? '';
        $oltIp    = $ont->olt_ip ?? Setting::get('gpon_olt_ip', '10.133.67.99');
        $olt      = $this->getOltConfig($oltIp);

        $serial   = $ont->serial;
        $ontId    = $ont->ont_id;
        $servPort = $ont->service_port;
        $portNum  = $ont->port_num;
        $vlan     = $olt->getVlan($portNum);

        $portIndex = $ont->port_index ?? $this->getPortIndexForSerialOnOlt($serial, $olt);
        if (!$portIndex) {
            throw new RuntimeException('Nepodařilo se zjistit port index pro ONT ' . $serial);
        }

        $dum    = $houseNo !== '' ? $houseNo : $userName;
        $snHex  = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $serial));
        $port   = $portIndex;
        $opt    = $this->snmpOptForOlt($olt, '-M ' . escapeshellarg(base_path('resources/mibs')) . ' -Ir');
        $SP1    = $servPort + 1;
        $eOltIp = escapeshellarg($oltIp);

        // Registrace ONT
        $dat =
            "hwGponDeviceOntAuthMethod.{$port}.{$ontId} = sn " .
            "hwGponDeviceOntSn.{$port}.{$ontId} x {$snHex} " .
            "hwGponDeviceOntManagementMode.{$port}.{$ontId} = omci " .
            "hwGponDeviceOntEntryStatus.{$port}.{$ontId} = createAndGo " .
            "hwGponDeviceOntLineProfName.{$port}.{$ontId} = " . escapeshellarg($olt->line_prof) . " " .
            "hwGponDeviceOntServiceProfName.{$port}.{$ontId} = " . escapeshellarg($olt->service_prof) . " " .
            "hwGponDeviceOntDespt.{$port}.{$ontId} = " . escapeshellarg($dum);

        $out = shell_exec("snmpset {$opt} -m HUAWEI-XPON-MIB {$eOltIp} {$dat} 2>&1; echo $?");
        $this->logGpon('/tmp/gpon_register.log', 'ONT REGISTER', "snmpset {$opt} -m HUAWEI-XPON-MIB {$eOltIp} {$dat}", $out);
        $this->assertNoSnmpError($out, 'Chyba při ONT registraci');

        // Ověření registrace – OLT musí vrátit active(1)
        $verifyOut = shell_exec("snmpget {$opt} -Oqv -m HUAWEI-XPON-MIB {$eOltIp} hwGponDeviceOntEntryStatus.{$port}.{$ontId} 2>&1");
        $this->logGpon('/tmp/gpon_register.log', 'ONT VERIFY', "snmpget hwGponDeviceOntEntryStatus.{$port}.{$ontId}", $verifyOut);
        if ($verifyOut === null || !str_contains(trim($verifyOut), 'active')) {
            throw new RuntimeException('Registrace ONT se nepodařila ověřit – OLT nevrátil stav active. Výstup: ' . trim((string) $verifyOut));
        }

        // Service-flow
        $eTrafficTable = escapeshellarg($olt->traffic_table);
        $dat1 =
            "hwExtSrvFlowPara1.{$SP1} = {$olt->getFrame()} " .
            "hwExtSrvFlowPara2.{$SP1} = {$olt->getSlot()} " .
            "hwExtSrvFlowPara3.{$SP1} = {$portNum} " .
            "hwExtSrvFlowPara4.{$SP1} = {$ontId} " .
            "hwExtSrvFlowPara5.{$SP1} = 1 " .
            "hwExtSrvFlowParaType.{$SP1} = gpon " .
            "hwExtSrvFlowVlanid.{$SP1} = {$vlan} " .
            "hwExtSrvFlowMultiServiceType.{$SP1} = byUserVlan " .
            "hwExtSrvFlowMultiServiceUserPara.{$SP1} = 1 " .
            "hwExtSrvFlowRowStatus.{$SP1} = createAndGo " .
            "hwExtSrvFlowInboundTrafficTableName.{$SP1} = {$eTrafficTable} " .
            "hwExtSrvFlowOutboundTrafficTableName.{$SP1} = {$eTrafficTable}";

        $out1 = shell_exec("snmpset {$opt} -m HUAWEI-ETHERLIKE-EXT-MIB {$eOltIp} {$dat1} 2>&1; echo $?");
        $this->logGpon('/tmp/gpon_register.log', 'ONT SERVICE-FLOW', "snmpset {$opt} -m HUAWEI-ETHERLIKE-EXT-MIB {$eOltIp} {$dat1}", $out1);
        $this->assertNoSnmpError($out1, 'Chyba při vytváření service-flow');

        $updateData = [
            'house_no'   => $houseNo,
            'reg_status' => 'registered',
            'port_index' => $portIndex,
            'vlan'       => $vlan,
            'user_name'  => $ont->user_name ?? auth()->user()?->login ?? null,
        ];

        $city = $olt->geocode_city ?? '';
        if ($houseNo !== '' && !empty($city)) {
            $url  = 'https://nominatim.openstreetmap.org/search?q=' . urlencode($city . ' ' . $houseNo . ', Czech Republic') . '&format=json&limit=1&countrycodes=cz';
            $ctx  = stream_context_create(['http' => ['header' => "User-Agent: freenetis-laravel/1.0\r\n", 'timeout' => 5]]);
            Log::debug('GPON geocode request', ['url' => $url]);
            try {
                $raw = file_get_contents($url, false, $ctx);
                if ($raw === false) {
                    Log::warning('GPON geocode failed: file_get_contents returned false', ['url' => $url]);
                }
                $geo = $raw !== false ? json_decode($raw, true) : null;
            } catch (\Exception $e) {
                Log::warning('GPON geocode failed: ' . $e->getMessage());
                $geo = null;
            }
            $lat = isset($geo[0]['lat']) ? (float) $geo[0]['lat'] : null;
            $lng = isset($geo[0]['lon']) ? (float) $geo[0]['lon'] : null;
            Log::debug('GPON geocode result', ['lat' => $lat, 'lng' => $lng, 'raw' => $raw ?? null]);
            if ($lat !== null && $lat >= -90 && $lat <= 90 && $lng !== null && $lng >= -180 && $lng <= 180) {
                $updateData['gps_lat'] = $lat;
                $updateData['gps_lng'] = $lng;
            }
        } else {
            Log::debug('GPON geocode skipped', ['houseNo' => $houseNo, 'city' => $city]);
        }

        $ont->update($updateData);
    }

    /**
     * Odstraní ONT z OLT a označí záznam jako 'removed'.
     */
    public function removeOntById(int $id): void
    {
        $ont   = Ont::findOrFail($id);
        $oltIp = $ont->olt_ip ?? Setting::get('gpon_olt_ip', '10.133.67.99');
        $olt   = $this->getOltConfig($oltIp);

        $ontId    = $ont->ont_id;
        $servPort = $ont->service_port;
        $serial   = $ont->serial;

        $portIndex = $ont->port_index ?? $this->getPortIndexForSerialOnOlt($serial, $olt);
        if (!$portIndex) {
            throw new RuntimeException('Nepodařilo se zjistit port index pro ONT ' . $serial);
        }

        $port   = $portIndex;
        $SP1    = $servPort + 1;
        $opt    = $this->snmpOptForOlt($olt, '-M ' . escapeshellarg(base_path('resources/mibs')) . ' -Ir');
        $eOltIp = escapeshellarg($oltIp);

        $dat1 = "hwExtSrvFlowRowStatus.{$SP1} = destroy";
        $out1 = shell_exec("snmpset {$opt} -m HUAWEI-ETHERLIKE-EXT-MIB {$eOltIp} {$dat1} 2>&1; echo $?");
        $this->logGpon('/tmp/gpon_remove.log', 'ONT REMOVE SERVICE-FLOW', "snmpset {$opt} -m HUAWEI-ETHERLIKE-EXT-MIB {$eOltIp} {$dat1}", $out1);
        $this->assertNoSnmpError($out1, 'Chyba při mazání service-flow');

        $dat = "hwGponDeviceOntEntryStatus.{$port}.{$ontId} = destroy";
        $out = shell_exec("snmpset {$opt} -m HUAWEI-XPON-MIB {$eOltIp} {$dat} 2>&1; echo $?");
        $this->logGpon('/tmp/gpon_remove.log', 'ONT REMOVE', "snmpset {$opt} -m HUAWEI-XPON-MIB {$eOltIp} {$dat}", $out);
        $this->assertNoSnmpError($out, 'Chyba při mazání ONT');

        $ont->update(['reg_status' => 'removed']);
    }

    /**
     * Výpočet ont_id, service_port a vlan pro daný port.
     */
    public function computeOntIdServicePortVlan(int $portNum, int $lastOntId, GponOlt $olt): array
    {
        $ontId       = $lastOntId + 1;
        $vlan        = $olt->getVlan($portNum);
        $servicePort = $lastOntId + ($portNum * 1000) + 1;

        return [$ontId, $servicePort, $vlan];
    }

    /**
     * Vrací live SNMP data (SNMPv3) pro registrovanou ONT.
     */
    public function getOntDetails(Ont $ont): array
    {
        $oltIp = $ont->olt_ip ?? Setting::get('gpon_olt_ip', '10.133.67.99');
        $olt   = $this->getOltConfig($oltIp);
        $pi    = (int) $ont->port_index;
        $oi    = (int) $ont->ont_id;

        $base = '1.3.6.1.4.1.2011.6.128.1.1.2';
        $oids = implode(' ', [
            "{$base}.51.1.4.{$pi}.{$oi}",    // [0] rx_power   (/100 = dBm)
            "{$base}.51.1.3.{$pi}.{$oi}",    // [1] tx_power   (/100 = dBm)
            "{$base}.51.1.5.{$pi}.{$oi}",    // [2] voltage    (/1000 = V)
            "{$base}.51.1.2.{$pi}.{$oi}",    // [3] current    (mA)
            "{$base}.51.1.1.{$pi}.{$oi}",    // [4] temperature (°C)
            "{$base}.46.1.20.{$pi}.{$oi}",   // [5] distance   (m)
            "{$base}.62.1.22.{$pi}.{$oi}.1", // [6] eth_status
            "{$base}.62.1.3.{$pi}.{$oi}.1",  // [7] eth_duplex
            "{$base}.62.1.4.{$pi}.{$oi}.1",  // [8] eth_speed
            "{$base}.46.1.15.{$pi}.{$oi}",   // [9] online status (1=online)
        ]);

        $optCmd    = "snmpget " . $this->snmpOptForOlt($olt) . " -Oqv " . escapeshellarg($oltIp) . " {$oids} 2>&1";
        $optOutput = [];
        exec($optCmd, $optOutput);

        $val = function (int $i) use ($optOutput): ?int {
            if (!isset($optOutput[$i])) return null;
            $s = trim($optOutput[$i]);
            return preg_match('/^-?\d+$/', $s) ? (int) $s : null;
        };

        $rxRaw    = $val(0);
        $txRaw    = $val(1);
        $voltRaw  = $val(2);
        $currRaw  = $val(3);
        $tempRaw  = $val(4);
        $distRaw  = $val(5);
        $ethSt    = $val(6);
        $ethDup   = $val(7);
        $ethSpd   = $val(8);
        $onlineRaw = $val(9);

        $na = 2147483647;

        $rx      = ($rxRaw   === null || $rxRaw   === $na) ? 'DOWN' : round($rxRaw   / 100, 2);
        $tx      = ($txRaw   === null || $txRaw   === $na) ? 'DOWN' : round($txRaw   / 100, 2);
        $voltage = ($voltRaw === null || $voltRaw === $na) ? 'DOWN' : round($voltRaw / 1000, 3);
        $current = ($currRaw === null || $currRaw === $na) ? 'DOWN' : $currRaw;
        $temp    = ($tempRaw === null || $tempRaw === $na) ? 'DOWN' : $tempRaw;
        $dist    = ($distRaw === null || $distRaw < 0)     ? 'DOWN' : $distRaw;

        $ethStatus = $ethSt  === 1 ? 'UP' : 'DOWN';
        $duplex    = match($ethDup) { 5 => 'Full', 4 => 'Half', default => 'DOWN' };
        $speed     = match($ethSpd) { 5 => '10Mbit', 6 => '100Mbit', 7 => '1Gbit', default => 'DOWN' };

        $infoOids = implode(' ', [
            "{$base}.52.1.3.{$pi}.{$oi}",
            "{$base}.52.1.10.{$pi}.{$oi}",
            "{$base}.52.1.11.{$pi}.{$oi}",
        ]);

        $infoCmd    = "snmpget " . $this->snmpOptForOlt($olt) . " -Oqav " . escapeshellarg($oltIp) . " {$infoOids} 2>&1";
        $infoOutput = [];
        exec($infoCmd, $infoOutput);

        $ontStatusRaw = isset($infoOutput[0]) ? (int) trim($infoOutput[0]) : null;
        $ontStatus    = match($ontStatusRaw) {
            9  => 'Working',
            10 => 'Online',
            5  => 'Offline',
            default => $ontStatusRaw !== null ? "Stav {$ontStatusRaw}" : '—',
        };
        $firmware = isset($infoOutput[1]) ? rtrim(trim($infoOutput[1], "\" \t\n\r\x0B"), "\x00.") : '—';
        $model    = isset($infoOutput[2]) ? rtrim(trim($infoOutput[2], "\" \t\n\r\x0B"), "\x00.") : '—';

        return [
            'rx_power'    => $rx,
            'tx_power'    => $tx,
            'voltage'     => $voltage,
            'current'     => $current,
            'temperature' => $temp,
            'distance'    => $dist,
            'eth_status'  => $ethStatus,
            'eth_duplex'  => $duplex,
            'eth_speed'   => $speed,
            'online'      => $onlineRaw === 1,
            'status'      => $ontStatus,
            'firmware'    => $firmware,
            'model'       => $model,
        ];
    }

    /**
     * Batch online stav — jeden snmpwalk na .46.1.15 per OLT.
     * Vrací pole ["{port_index}.{ont_id}" => true/false].
     */
    public function getBatchOnlineStatus(\Illuminate\Support\Collection $onts): array
    {
        $base    = '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.15';
        $status  = [];
        $grouped = $onts->groupBy('olt_ip');

        foreach ($grouped as $oltIp => $group) {
            try {
                $olt  = $this->getOltConfig($oltIp);
                $sOpt = sprintf(
                    '-v3 -l %s -u %s -a %s -A %s -x %s -X %s',
                    escapeshellarg($this->secLevel),
                    escapeshellarg($olt->snmp_user),
                    escapeshellarg($olt->snmp_auth_proto),
                    escapeshellarg($olt->snmp_auth_pass),
                    escapeshellarg($olt->snmp_priv_proto),
                    escapeshellarg($olt->snmp_priv_pass)
                );
            } catch (RuntimeException) {
                foreach ($group as $ont) {
                    $status["{$ont->port_index}.{$ont->ont_id}"] = false;
                }
                continue;
            }

            $output = [];
            exec("snmpwalk {$sOpt} -On " . escapeshellarg($oltIp) . " {$base} 2>&1", $output);

            foreach ($output as $line) {
                if (preg_match('/\.46\.1\.15\.(\d+)\.(\d+)\s*=\s*\S+\s*(\d+)/', $line, $m)) {
                    $status["{$m[1]}.{$m[2]}"] = $m[3] === '1';
                }
            }

            foreach ($group as $ont) {
                $key = "{$ont->port_index}.{$ont->ont_id}";
                if (!isset($status[$key])) {
                    $status[$key] = false;
                }
            }
        }

        return $status;
    }

    // ── Privátní pomocné metody ──────────────────────────────────────────────

    private function scanOlt(GponOlt $olt): int
    {
        $serials  = $this->getOntSerialsForOlt($olt);
        $countNew = 0;

        foreach ($serials as $serial) {
            if (!preg_match('/^48[0-9A-Fa-f]{14}$/', $serial)) {
                Log::warning('GPON scan: neplatné sériové číslo přeskočeno', ['serial' => $serial, 'olt' => $olt->ip]);
                continue;
            }

            $existing = Ont::where('serial', $serial)->first();
            if ($existing && $existing->reg_status === 'registered') {
                continue;
            }

            $portIndex = $this->getPortIndexForSerialOnOlt($serial, $olt);
            if ($portIndex === null) {
                continue;
            }

            $ifName   = $this->getIfNameByIndexOnOlt($portIndex, $olt);
            $gponPort = (str_contains($ifName, 'No Such') || str_contains($ifName, 'error') || strlen($ifName) > 64)
                ? 'unknown'
                : $ifName;

            $portNum   = $this->extractPortNum($gponPort);
            $lastOntId = Ont::where('gpon_port', $gponPort)->max('ont_id') ?? 0;
            [$ontId, $servicePort, $vlan] = $this->computeOntIdServicePortVlan($portNum, (int) $lastOntId, $olt);

            Log::debug('GPON scan', ['serial' => $serial, 'olt' => $olt->ip, 'port' => $gponPort]);

            if (!$existing) {
                Ont::create([
                    'olt_ip'       => $olt->ip,
                    'serial'       => $serial,
                    'gpon_port'    => $gponPort,
                    'port_num'     => $portNum,
                    'port_index'   => $portIndex,
                    'ont_id'       => $ontId,
                    'service_port' => $servicePort,
                    'vlan'         => $vlan,
                    'reg_status'   => 'new',
                    'user_name'    => auth()->user()?->login ?? null,
                ]);
                $countNew++;
            }
        }

        return $countNew;
    }

    private function getOntSerialsForOlt(GponOlt $olt): array
    {
        $cmd = sprintf(
            'snmpwalk -v3 -l %s -u %s -a %s -A %s -x %s -X %s %s %s 2>&1',
            escapeshellarg($this->secLevel),
            escapeshellarg($olt->snmp_user),
            escapeshellarg($olt->snmp_auth_proto),
            escapeshellarg($olt->snmp_auth_pass),
            escapeshellarg($olt->snmp_priv_proto),
            escapeshellarg($olt->snmp_priv_pass),
            escapeshellarg($olt->ip),
            escapeshellarg(self::OID_ONT_SERIALS)
        );

        $output = shell_exec($cmd);
        if ($output === null) {
            return [];
        }

        $serials = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($output)) as $line) {
            $pos = stripos($line, 'Hex-STRING:');
            if ($pos === false) continue;
            $hex = strtoupper(str_replace(' ', '', trim(substr($line, $pos + 11))));
            if ($hex !== '') $serials[] = $hex;
        }

        return array_values(array_unique($serials));
    }

    private function getPortIndexForSerialOnOlt(string $serial, GponOlt $olt): ?int
    {
        $cmd = sprintf(
            'snmpwalk -v3 -l %s -u %s -a %s -A %s -x %s -X %s -On %s %s 2>&1',
            escapeshellarg($this->secLevel),
            escapeshellarg($olt->snmp_user),
            escapeshellarg($olt->snmp_auth_proto),
            escapeshellarg($olt->snmp_auth_pass),
            escapeshellarg($olt->snmp_priv_proto),
            escapeshellarg($olt->snmp_priv_pass),
            escapeshellarg($olt->ip),
            escapeshellarg(self::OID_ONT_SERIALS)
        );

        $output = shell_exec($cmd);
        if ($output === null) return null;

        $serial = strtoupper($serial);
        foreach (preg_split('/\r\n|\r|\n/', trim($output)) as $line) {
            $pos = stripos($line, 'Hex-STRING:');
            if ($pos === false) continue;
            $hex = strtoupper(str_replace(' ', '', trim(substr($line, $pos + 11))));
            if ($hex !== $serial) continue;
            $eqPos = strpos($line, '=');
            if ($eqPos === false) continue;
            $parts = explode('.', ltrim(trim(substr($line, 0, $eqPos)), '.'));
            $nums  = array_values(array_filter(array_map('trim', $parts), 'ctype_digit'));
            if (count($nums) < 2) continue;
            return (int) $nums[count($nums) - 2];
        }

        return null;
    }

    private function getIfNameByIndexOnOlt(int $index, GponOlt $olt): string
    {
        $oid = self::OID_IF_NAME . '.' . $index;
        $cmd = sprintf(
            'snmpget -v3 -l %s -u %s -a %s -A %s -x %s -X %s %s %s 2>&1',
            escapeshellarg($this->secLevel),
            escapeshellarg($olt->snmp_user),
            escapeshellarg($olt->snmp_auth_proto),
            escapeshellarg($olt->snmp_auth_pass),
            escapeshellarg($olt->snmp_priv_proto),
            escapeshellarg($olt->snmp_priv_pass),
            escapeshellarg($olt->ip),
            escapeshellarg($oid)
        );

        $output = shell_exec($cmd);
        if ($output === null) return 'unknown';

        $line = trim($output);
        if (str_contains($line, 'No Such Instance') || str_contains($line, 'No Such Object')) {
            return 'unknown';
        }

        $pos = strpos($line, '=');
        if ($pos === false) return 'unknown';
        $right     = trim(substr($line, $pos + 1));
        $colonPos  = strpos($right, ':');
        if ($colonPos !== false) $right = substr($right, $colonPos + 1);
        return trim($right, " \t\n\r\0\x0B\"'");
    }

    private function extractPortNum(string $ifName): int
    {
        if (preg_match('/(\d+)\s*$/', $ifName, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private function snmpOptForOlt(GponOlt $olt, string $extra = ''): string
    {
        return trim(sprintf(
            '%s -v 3 -a %s -x %s -l %s -u %s -A %s -X %s',
            $extra,
            escapeshellarg($olt->snmp_auth_proto),
            escapeshellarg($olt->snmp_priv_proto),
            escapeshellarg($this->secLevel),
            escapeshellarg($olt->snmp_user),
            escapeshellarg($olt->snmp_auth_pass),
            escapeshellarg($olt->snmp_priv_pass)
        ));
    }

    private function assertNoSnmpError(?string $out, string $msg): void
    {
        if ($out === null) {
            throw new RuntimeException($msg . ': žádný výstup');
        }
        foreach (['Timeout', 'No Such', 'No Response', 'Error'] as $keyword) {
            if (stripos($out, $keyword) !== false) {
                throw new RuntimeException($msg . ': ' . $out);
            }
        }
    }

    private function logGpon(string $file, string $label, string $cmd, ?string $out): void
    {
        // Audit log z `/tmp/gpon_*.log` přesměrujeme do storage/logs s mode 0600.
        // Soubory v /tmp byly world-readable a obsahovaly SNMPv3 credentials.
        $basename = basename($file);
        $target   = storage_path('logs/' . $basename);

        // Redakce SNMPv3 credentials z command line (-A authpass, -X privpass).
        // Pokrývá tři tvary: -A 'pass'  /  -A "pass"  /  -A pass
        $safeCmd = preg_replace(
            '/(-[AX]\s+)(?:\'[^\']*\'|"[^"]*"|\S+)/',
            '$1[REDACTED]',
            $cmd
        );

        $entry = "==== {$label} ====\nTIME: " . date('Y-m-d H:i:s') . "\nCMD:  {$safeCmd}\nOUT:\n{$out}\n\n";

        try {
            $isNew = !file_exists($target);
            file_put_contents($target, $entry, FILE_APPEND | LOCK_EX);
            if ($isNew) {
                @chmod($target, 0600);
            }
        } catch (\Exception $e) {
            Log::warning('GPON log write failed: ' . $e->getMessage());
        }
    }
}
