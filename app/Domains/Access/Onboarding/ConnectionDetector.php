<?php

namespace App\Domains\Access\Onboarding;

use App\Domains\Access\Repositories\ConnectionRequestRepository;
use App\Models\Setting;
use App\Services\SnmpMacDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rozpoznání nově připojeného zařízení: IP klienta je volná neregistrovaná
 * adresa v některé podsíti („neznámé zařízení") → podsíť + MAC. MAC se zapnutým
 * PPPoE nejdřív z RADIUS accountingu (aktivní session podle pool IP →
 * Calling-Station-Id), jinak SNMP (MAC/IP režim) — během přechodu oba.
 */
class ConnectionDetector
{
    public function __construct(private ConnectionRequestRepository $requests)
    {
    }

    /** @return ?array{subnet_id:int, ip:string, mac:?string} */
    public function detect(string $ip): ?array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $subnetId = $this->requests->freeSubnetFor($ip);
        if ($subnetId === null) {
            return null;
        }
        return ['subnet_id' => $subnetId, 'ip' => $ip, 'mac' => $this->mac($subnetId, $ip)];
    }

    public function mac(int $subnetId, string $ip): ?string
    {
        if (Setting::get('pppoe_enabled', 0)) {
            try {
                $raw = DB::table('radacct')
                    ->where('framedipaddress', $ip)
                    ->whereNull('acctstoptime')
                    ->orderByDesc('acctstarttime')
                    ->value('callingstationid');
                $mac = $raw ? strtoupper(str_replace('-', ':', trim($raw))) : null;
                if ($mac && preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
                    return $mac;
                }
            } catch (\Throwable $e) {
                Log::warning('radacct MAC lookup selhal', ['ip' => $ip, 'error' => $e->getMessage()]);
            }
        }
        return app(SnmpMacDetector::class)->detectForSubnet($subnetId, $ip);
    }

    public static function ipInSubnet(string $ip, string $network, string $netmask): bool
    {
        return (ip2long($ip) & ip2long($netmask)) === (ip2long($network) & ip2long($netmask));
    }
}
