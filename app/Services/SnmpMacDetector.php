<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Detects MAC address of a device via SNMP on its subnet gateway.
 * Ported from Kohana Snmp_Factory + driver classes.
 */
class SnmpMacDetector
{
    private string $community = 'public';
    private int    $timeout   = 3_000_000; // microseconds
    private int    $retries   = 5;

    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * Detect MAC address of $targetIp via the gateway of $subnetId.
     * Returns MAC in AA:BB:CC:DD:EE:FF format, or null on failure.
     */
    public function detectForSubnet(int $subnetId, string $targetIp): ?string
    {
        if (!Setting::get('snmp_enabled')) {
            return null;
        }

        $gateway = DB::selectOne(
            'SELECT ip_address FROM ip_addresses WHERE gateway = 1 AND subnet_id = ? LIMIT 1',
            [$subnetId]
        );

        if (!$gateway) {
            return null;
        }

        return $this->detect($gateway->ip_address, $targetIp);
    }

    /**
     * Detect MAC address of $targetIp by querying $gatewayIp via SNMP.
     * Tries all drivers; DHCP table first, ARP table as fallback.
     */
    public function detect(string $gatewayIp, string $targetIp): ?string
    {
        if (!filter_var($gatewayIp, FILTER_VALIDATE_IP)
            || !filter_var($targetIp, FILTER_VALIDATE_IP)) {
            return null;
        }

        $drivers = ['mikrotik', 'linux'];

        foreach ($drivers as $driver) {
            if (!$this->isCompatible($driver, $gatewayIp)) {
                continue;
            }

            // Try DHCP table first
            try {
                $mac = $this->getDhcpMac($driver, $gatewayIp, $targetIp);
                if ($mac) {
                    return $mac;
                }
            } catch (\Exception $e) {
                // fall through to ARP
            }

            // Fallback: ARP table
            try {
                return $this->getArpMac($driver, $gatewayIp, $targetIp);
            } catch (\Exception $e) {
                Log::warning('SNMP MAC detection failed', [
                    'driver'  => $driver,
                    'gateway' => $gatewayIp,
                    'target'  => $targetIp,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    // ── Driver compatibility ──────────────────────────────────────────────────

    private function isCompatible(string $driver, string $gatewayIp): bool
    {
        try {
            $row = @snmp2_get(
                $gatewayIp, $this->community,
                'iso.3.6.1.2.1.1.1.0',
                $this->timeout, $this->retries
            );
        } catch (\Exception $e) {
            return false;
        }

        if ($row === false) {
            return false;
        }

        if (!preg_match('/STRING: "?(.*?)"?\s*$/', $row, $m)) {
            return false;
        }

        $desc = $m[1];

        return match ($driver) {
            'mikrotik' => str_starts_with($desc, 'RouterOS'),
            'linux'    => str_starts_with($desc, 'Linux') || str_starts_with($desc, 'S6720'),
            default    => false,
        };
    }

    // ── DHCP MAC detection ────────────────────────────────────────────────────

    private function getDhcpMac(string $driver, string $gatewayIp, string $targetIp): ?string
    {
        // Both Mikrotik and Linux use the same OID (Mikrotik-specific DHCP MIB)
        try {
            $row = @snmp2_get(
                $gatewayIp, $this->community,
                'iso.3.6.1.2.1.9999.1.1.6.4.1.8.' . $targetIp,
                $this->timeout, $this->retries
            );
        } catch (\Exception $e) {
            throw new \RuntimeException('DHCP SNMP error: ' . $e->getMessage());
        }

        if ($row === false) {
            throw new \RuntimeException('DHCP SNMP returned false');
        }

        // Mikrotik format: Hex-STRING: [optional type+len prefix] AA BB CC DD EE FF
        // Response may include 2-byte prefix (e.g. "01 06") before the 6-byte MAC
        if (preg_match('/Hex-STRING:\s*(([0-9a-fA-F]{2}\s+)+[0-9a-fA-F]{2})/', $row, $m)) {
            $bytes = preg_split('/\s+/', trim($m[1]));
            $bytes = array_filter($bytes, fn($b) => $b !== '');
            // Take the last 6 bytes — strips any type/length prefix
            $mac = array_slice(array_values($bytes), -6);
            if (count($mac) === 6) {
                return strtoupper(implode(':', $mac));
            }
        }

        // Linux format: STRING: "AA BB CC DD EE FF"
        if (preg_match('/STRING: "(([0-9a-fA-F]{2}\s){5}[0-9a-fA-F]{2})"/', $row, $m)) {
            $bytes = preg_split('/\s+/', trim($m[1]));
            $mac = array_slice(array_values(array_filter($bytes, fn($b) => $b !== '')), -6);
            if (count($mac) === 6) {
                return strtoupper(implode(':', $mac));
            }
        }

        throw new \RuntimeException('Cannot parse DHCP SNMP response: ' . $row);
    }

    // ── ARP MAC detection ─────────────────────────────────────────────────────

    private function getArpMac(string $driver, string $gatewayIp, string $targetIp): string
    {
        // Mikrotik: ipNetToMediaPhysAddress (RFC 1213)
        // Linux:    atPhysAddress
        $oid = ($driver === 'mikrotik')
            ? 'iso.3.6.1.2.1.4.22.1.2'
            : 'iso.3.6.1.2.1.3.1.1.2';

        $table = @snmp2_real_walk(
            $gatewayIp, $this->community,
            $oid,
            $this->timeout, $this->retries
        );

        if ($table === false || !is_array($table)) {
            throw new \RuntimeException('ARP walk failed on ' . $gatewayIp);
        }

        $regex = '/Hex-STRING:.*?(([0-9a-fA-F]{2}\s){5}[0-9a-fA-F]{2})/';

        foreach ($table as $key => $val) {
            if (!str_ends_with($key, '.' . $targetIp)) {
                continue;
            }
            if (preg_match($regex, $val, $m)) {
                $pieces = array_map(
                    fn($p) => str_pad($p, 2, '0', STR_PAD_LEFT),
                    explode(' ', trim($m[1]))
                );
                return strtoupper(implode(':', $pieces));
            }
        }

        throw new \RuntimeException($targetIp . ' not found in ARP table on ' . $gatewayIp);
    }
}
