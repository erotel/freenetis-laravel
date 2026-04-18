<?php

namespace App\Services;

use App\Models\Ont;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GponService
{
    private string $host;
    private string $secName;
    private string $authProtocol;
    private string $authPass;
    private string $privProtocol;
    private string $privPass;
    private string $secLevel = 'authPriv';

    // OIDs (stejné jako v gpon-app)
    private const OID_ONT_SERIALS = '1.3.6.1.4.1.2011.6.128.1.1.2.48.1.2';
    private const OID_IF_NAME     = '1.3.6.1.2.1.31.1.1.1.1';

    public function __construct()
    {
        $this->host         = Setting::get('gpon_olt_ip', '10.133.67.99');
        $this->secName      = Setting::get('gpon_snmp_user', 'admin');
        $this->authProtocol = Setting::get('gpon_snmp_auth_proto', 'SHA');
        $this->authPass     = Setting::get('gpon_snmp_auth_pass', '');
        $this->privProtocol = Setting::get('gpon_snmp_priv_proto', 'AES');
        $this->privPass     = Setting::get('gpon_snmp_priv_pass', '');
    }

    /**
     * Skenuje OLT pro nové ONT a uloží je do DB.
     * Vrací počet nově nalezených ONT.
     */
    public function scanNewOnts(): int
    {
        $serials = $this->getOntSerials();
        $countNew = 0;

        foreach ($serials as $serial) {
            if (substr($serial, 0, 2) !== '48') {
                continue;
            }

            $existing = Ont::where('serial', $serial)->first();
            if ($existing && $existing->reg_status === 'registered') {
                continue;
            }

            $portIndex = $this->getPortIndexForSerial($serial);
            if ($portIndex === null) {
                continue;
            }

            $ifName   = $this->getIfNameByIndex($portIndex);
            $gponPort = $ifName;

            if (str_contains($gponPort, 'No Such') || str_contains($gponPort, 'error') || strlen($gponPort) > 64) {
                $gponPort = 'unknown';
            }

            $portNum = $this->extractPortNum($gponPort);

            Log::debug('GPON scan', ['serial' => $serial, 'port' => $gponPort, 'portIndex' => $portIndex]);

            $lastOntId = Ont::where('gpon_port', $gponPort)->max('ont_id') ?? 0;
            [$ontId, $servicePort, $vlan] = $this->computeOntIdServicePortVlan($portNum, (int) $lastOntId);

            if (!$existing) {
                Ont::create([
                    'olt_ip'       => $this->host,
                    'serial'       => $serial,
                    'gpon_port'    => $gponPort,
                    'port_num'     => $portNum,
                    'port_index'   => $portIndex,
                    'ont_id'       => $ontId,
                    'service_port' => $servicePort,
                    'vlan'         => $vlan,
                    'reg_status'   => 'new',
                ]);
                $countNew++;
            }
        }

        return $countNew;
    }

    /**
     * Registruje ONT na OLT podle ID záznamu v DB.
     */
    public function registerOntById(int $id, string $houseNo = '', ?string $userName = null): void
    {
        $ont = Ont::findOrFail($id);
        $userName = $userName ?? '';

        $ontData  = $ont->toArray();
        $serial   = $ont->serial;
        $ontId    = $ont->ont_id;
        $servPort = $ont->service_port;
        $vlan     = $ont->vlan;
        $portNum  = $ont->port_num;

        $portIndex = $ont->port_index ?? $this->getPortIndexForSerial($serial);
        if (!$portIndex) {
            throw new RuntimeException('Nepodařilo se zjistit port index pro ONT ' . $serial);
        }

        $dum    = $houseNo !== '' ? $houseNo : $userName;
        $snHex  = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $serial));
        $port   = $portIndex;
        $opt    = $this->snmpOpt('-M /var/www/gpon-app/mibs -Ir');
        $SP1    = $servPort + 1;

        // Registrace ONT
        $dat =
            "hwGponDeviceOntAuthMethod.{$port}.{$ontId} = sn " .
            "hwGponDeviceOntSn.{$port}.{$ontId} x {$snHex} " .
            "hwGponDeviceOntManagementMode.{$port}.{$ontId} = omci " .
            "hwGponDeviceOntEntryStatus.{$port}.{$ontId} = createAndGo " .
            "hwGponDeviceOntLineProfName.{$port}.{$ontId} = line-profile_default_0 " .
            "hwGponDeviceOntServiceProfName.{$port}.{$ontId} = sfu-aio-dmc " .
            "hwGponDeviceOntDespt.{$port}.{$ontId} = \"{$dum}\"";

        $out = shell_exec("snmpset {$opt} -m HUAWEI-XPON-MIB {$this->host} {$dat} 2>&1; echo $?");
        $this->logGpon('/tmp/gpon_register.log', 'ONT REGISTER', "snmpset {$opt} -m HUAWEI-XPON-MIB {$this->host} {$dat}", $out);
        $this->assertNoSnmpError($out, 'Chyba při ONT registraci');

        // Service-flow
        $dat1 =
            "hwExtSrvFlowPara1.{$SP1} = 0 " .
            "hwExtSrvFlowPara2.{$SP1} = 1 " .
            "hwExtSrvFlowPara3.{$SP1} = {$portNum} " .
            "hwExtSrvFlowPara4.{$SP1} = {$ontId} " .
            "hwExtSrvFlowPara5.{$SP1} = 1 " .
            "hwExtSrvFlowParaType.{$SP1} = gpon " .
            "hwExtSrvFlowVlanid.{$SP1} = {$vlan} " .
            "hwExtSrvFlowMultiServiceType.{$SP1} = byUserVlan " .
            "hwExtSrvFlowMultiServiceUserPara.{$SP1} = 1 " .
            "hwExtSrvFlowRowStatus.{$SP1} = createAndGo " .
            "hwExtSrvFlowInboundTrafficTableName.{$SP1} = \"int\" " .
            "hwExtSrvFlowOutboundTrafficTableName.{$SP1} = \"int\"";

        $out1 = shell_exec("snmpset {$opt} -m HUAWEI-ETHERLIKE-EXT-MIB {$this->host} {$dat1} 2>&1; echo $?");
        $this->logGpon('/tmp/gpon_register.log', 'ONT SERVICE-FLOW', "snmpset {$opt} -m HUAWEI-ETHERLIKE-EXT-MIB {$this->host} {$dat1}", $out1);
        $this->assertNoSnmpError($out1, 'Chyba při vytváření service-flow');

        $ont->update([
            'house_no'   => $houseNo,
            'user_name'  => $userName,
            'reg_status' => 'registered',
            'port_index' => $portIndex,
        ]);
    }

    /**
     * Odstraní ONT z OLT a označí záznam jako 'removed'.
     */
    public function removeOntById(int $id): void
    {
        $ont = Ont::findOrFail($id);

        $ontId    = $ont->ont_id;
        $servPort = $ont->service_port;
        $serial   = $ont->serial;

        $portIndex = $ont->port_index ?? $this->getPortIndexForSerial($serial);
        if (!$portIndex) {
            throw new RuntimeException('Nepodařilo se zjistit port index pro ONT ' . $serial);
        }
        $port = $portIndex;
        $SP1  = $servPort + 1;
        $opt  = $this->snmpOpt('-M /var/www/gpon-app/mibs -Ir');

        // Smazání service-flow
        $dat1 = "hwExtSrvFlowRowStatus.{$SP1} = destroy";
        $out1 = shell_exec("snmpset {$opt} -m HUAWEI-ETHERLIKE-EXT-MIB {$this->host} {$dat1} 2>&1; echo $?");
        $this->logGpon('/tmp/gpon_remove.log', 'ONT REMOVE SERVICE-FLOW', "snmpset {$opt} -m HUAWEI-ETHERLIKE-EXT-MIB {$this->host} {$dat1}", $out1);
        $this->assertNoSnmpError($out1, 'Chyba při mazání service-flow');

        // Smazání ONT
        $dat = "hwGponDeviceOntEntryStatus.{$port}.{$ontId} = destroy";
        $out = shell_exec("snmpset {$opt} -m HUAWEI-XPON-MIB {$this->host} {$dat} 2>&1; echo $?");
        $this->logGpon('/tmp/gpon_remove.log', 'ONT REMOVE', "snmpset {$opt} -m HUAWEI-XPON-MIB {$this->host} {$dat}", $out);
        $this->assertNoSnmpError($out, 'Chyba při mazání ONT');

        $ont->update(['reg_status' => 'removed']);
    }

    /**
     * Výpočet ont_id, service_port a vlan pro daný port (0-15).
     */
    public function computeOntIdServicePortVlan(int $portNum, int $lastOntId): array
    {
        $pr    = $lastOntId;
        $ontId = $pr + 1;
        $vlan  = 200;

        $offsets = [
            0  => [1,     200],
            1  => [1001,  200],
            2  => [2001,  200],
            3  => [3001,  200],
            4  => [4001,  201],
            5  => [5001,  201],
            6  => [6001,  202],
            7  => [7001,  202],
            8  => [8001,  202],
            9  => [9001,  201],
            10 => [10001, 203],
            11 => [11001, 203],
            12 => [12001, 212],
            13 => [13001, 213],
            14 => [14001, 214],
            15 => [15001, 215],
        ];

        [$offset, $vlan] = $offsets[$portNum] ?? [1, 200];
        $servicePort = $pr + $offset;

        return [$ontId, $servicePort, $vlan];
    }

    /**
     * Vrací live SNMP data (SNMPv3) pro registrovanou ONT.
     * Sentinel hodnota 2147483647 (INT_MAX) = data nejsou dostupná.
     */
    public function getOntDetails(Ont $ont): array
    {
        $olt = $this->host;
        $pi  = (int) $ont->port_index;
        $oi  = (int) $ont->ont_id;

        $base = '1.3.6.1.4.1.2011.6.128.1.1.2';
        $oids = implode(' ', [
            "{$base}.51.1.4.{$pi}.{$oi}",    // rx_power   (/100 = dBm)
            "{$base}.51.1.3.{$pi}.{$oi}",    // tx_power   (/100 = dBm)
            "{$base}.51.1.5.{$pi}.{$oi}",    // voltage    (/1000 = V)
            "{$base}.51.1.2.{$pi}.{$oi}",    // current    (mA)
            "{$base}.51.1.1.{$pi}.{$oi}",    // temperature (°C)
            "{$base}.46.1.20.{$pi}.{$oi}",   // distance   (m)
            "{$base}.62.1.22.{$pi}.{$oi}.1", // eth_status
            "{$base}.62.1.3.{$pi}.{$oi}.1",  // eth_duplex
            "{$base}.62.1.4.{$pi}.{$oi}.1",  // eth_speed
        ]);

        // ── Optické parametry (.51, .46, .62) — dostupné jen na MA5800 ──────────
        $optCmd    = "snmpget " . $this->snmpOpt() . " -Oqv " . escapeshellarg($olt) . " {$oids} 2>&1";
        $optOutput = [];
        exec($optCmd, $optOutput);

        // Vrátí null pokud řádek není čistě numerický (např. "No Such Instance...")
        $val = function(int $i) use ($optOutput): ?int {
            if (!isset($optOutput[$i])) return null;
            $s = trim($optOutput[$i]);
            return preg_match('/^-?\d+$/', $s) ? (int) $s : null;
        };

        $rxRaw   = $val(0);
        $txRaw   = $val(1);
        $voltRaw = $val(2);
        $currRaw = $val(3);
        $tempRaw = $val(4);
        $distRaw = $val(5);
        $ethSt   = $val(6);
        $ethDup  = $val(7);
        $ethSpd  = $val(8);

        $na = 2147483647; // Huawei sentinel: "no data"

        $rx      = ($rxRaw   === null || $rxRaw   === $na) ? 'DOWN' : round($rxRaw   / 100, 2);
        $tx      = ($txRaw   === null || $txRaw   === $na) ? 'DOWN' : round($txRaw   / 100, 2);
        $voltage = ($voltRaw === null || $voltRaw === $na) ? 'DOWN' : round($voltRaw / 1000, 3);
        $current = ($currRaw === null || $currRaw === $na) ? 'DOWN' : $currRaw;
        $temp    = ($tempRaw === null || $tempRaw === $na) ? 'DOWN' : $tempRaw;
        $dist    = ($distRaw === null || $distRaw < 0)     ? 'DOWN' : $distRaw;

        $ethStatus = $ethSt  === 1 ? 'UP' : 'DOWN';
        $duplex    = match($ethDup) { 5 => 'Full', 4 => 'Half', default => 'DOWN' };
        $speed     = match($ethSpd) { 5 => '10Mbit', 6 => '100Mbit', 7 => '1Gbit', default => 'DOWN' };

        // ── Základní info z .52 — funguje na obou OLT ────────────────────────
        $infoOids = implode(' ', [
            "{$base}.52.1.3.{$pi}.{$oi}",  // stav ONT (INTEGER)
            "{$base}.52.1.10.{$pi}.{$oi}", // firmware (Hex-STRING → ASCII)
            "{$base}.52.1.11.{$pi}.{$oi}", // model    (Hex-STRING → ASCII)
        ]);

        // -Oa: zobraz OctetString jako ASCII místo hex bajtů
        $infoCmd    = "snmpget " . $this->snmpOpt() . " -Oqav " . escapeshellarg($olt) . " {$infoOids} 2>&1";
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
            'online'      => $ethStatus === 'UP',
            'status'      => $ontStatus,
            'firmware'    => $firmware,
            'model'       => $model,
        ];
    }

    // ── privátní pomocné metody ──────────────────────────────────────────────

    private function getOntSerials(): array
    {
        $cmd = sprintf(
            'snmpwalk -v3 -l %s -u %s -a %s -A %s -x %s -X %s %s %s 2>&1',
            escapeshellarg($this->secLevel),
            escapeshellarg($this->secName),
            escapeshellarg($this->authProtocol),
            escapeshellarg($this->authPass),
            escapeshellarg($this->privProtocol),
            escapeshellarg($this->privPass),
            escapeshellarg($this->host),
            escapeshellarg(self::OID_ONT_SERIALS)
        );

        $output = shell_exec($cmd);
        if ($output === null) {
            throw new RuntimeException('snmpwalk nevrátil žádný výstup');
        }

        $serials = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($output)) as $line) {
            $pos = stripos($line, 'Hex-STRING:');
            if ($pos === false) {
                continue;
            }
            $hex = strtoupper(str_replace(' ', '', trim(substr($line, $pos + 11))));
            if ($hex !== '') {
                $serials[] = $hex;
            }
        }

        return array_values(array_unique($serials));
    }

    private function getPortIndexForSerial(string $serial): ?int
    {
        $cmd = sprintf(
            'snmpwalk -v3 -l %s -u %s -a %s -A %s -x %s -X %s -On %s %s 2>&1',
            escapeshellarg($this->secLevel),
            escapeshellarg($this->secName),
            escapeshellarg($this->authProtocol),
            escapeshellarg($this->authPass),
            escapeshellarg($this->privProtocol),
            escapeshellarg($this->privPass),
            escapeshellarg($this->host),
            escapeshellarg(self::OID_ONT_SERIALS)
        );

        $output = shell_exec($cmd);
        if ($output === null) {
            return null;
        }

        $serial = strtoupper($serial);
        foreach (preg_split('/\r\n|\r|\n/', trim($output)) as $line) {
            $pos = stripos($line, 'Hex-STRING:');
            if ($pos === false) {
                continue;
            }
            $hex = strtoupper(str_replace(' ', '', trim(substr($line, $pos + 11))));
            if ($hex !== $serial) {
                continue;
            }
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }
            $parts = explode('.', ltrim(trim(substr($line, 0, $eqPos)), '.'));
            $nums  = array_values(array_filter(array_map('trim', $parts), 'ctype_digit'));
            if (count($nums) < 2) {
                continue;
            }
            return (int) $nums[count($nums) - 2];
        }

        return null;
    }

    private function getIfNameByIndex(int $index): string
    {
        $oid = self::OID_IF_NAME . '.' . $index;
        $cmd = sprintf(
            'snmpget -v3 -l %s -u %s -a %s -A %s -x %s -X %s %s %s 2>&1',
            escapeshellarg($this->secLevel),
            escapeshellarg($this->secName),
            escapeshellarg($this->authProtocol),
            escapeshellarg($this->authPass),
            escapeshellarg($this->privProtocol),
            escapeshellarg($this->privPass),
            escapeshellarg($this->host),
            escapeshellarg($oid)
        );

        $output = shell_exec($cmd);
        if ($output === null) {
            throw new RuntimeException('snmpget nevrátil žádný výstup');
        }

        $line = trim($output);

        if (str_contains($line, 'No Such Instance') || str_contains($line, 'No Such Object')) {
            return 'unknown';
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            throw new RuntimeException('Neznámý formát odpovědi snmpget: ' . $line);
        }
        $right = trim(substr($line, $pos + 1));
        $colonPos = strpos($right, ':');
        if ($colonPos !== false) {
            $right = substr($right, $colonPos + 1);
        }
        return trim($right, " \t\n\r\0\x0B\"'");
    }

    private function extractPortNum(string $ifName): int
    {
        // Handles formats: "GPON0/2/7", "GPON 0/1/0", "0/2/7", etc.
        // Extract the last integer in the string.
        if (preg_match('/(\d+)\s*$/', $ifName, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private function snmpOpt(string $extra = ''): string
    {
        return trim(sprintf(
            '%s -v 3 -a %s -x %s -l %s -u %s -A %s -X %s',
            $extra,
            escapeshellarg($this->authProtocol),
            escapeshellarg($this->privProtocol),
            escapeshellarg($this->secLevel),
            escapeshellarg($this->secName),
            escapeshellarg($this->authPass),
            escapeshellarg($this->privPass)
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
        $entry = "==== {$label} ====\nTIME: " . date('Y-m-d H:i:s') . "\nCMD:  {$cmd}\nOUT:\n{$out}\n\n";
        @file_put_contents($file, $entry, FILE_APPEND);
    }
}
