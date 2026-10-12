<?php

namespace App\Domains\Access\Dhcp;

use App\Models\Setting;

/**
 * Per-zařízení volby DHCP exportu z nastavení:
 *  - dhcp_relay_map     řádky `ID=rozhraní` → relay režim,
 *  - dhcp_force_radius  ID zařízení (řádky/čárky) → trvale use-radius=yes,
 *  - dhcp_force_tr101   ID zařízení → trvale support-broadband-tr101=yes.
 * Viz [[project_lineid_tr101_gotcha]].
 */
class DhcpDeviceSettings
{
    /**
     * Relay rozhraní pro dané DHCP zařízení. Null = zařízení v mapě není →
     * klasický interface-based export.
     */
    public function relayInterface(int $deviceId): ?string
    {
        $raw = (string) Setting::get('dhcp_relay_map', '');
        if ($raw === '') {
            return null;
        }
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$id, $iface] = array_map('trim', explode('=', $line, 2));
            if ($id === (string) $deviceId && $iface !== '') {
                return $iface;
            }
        }
        return null;
    }

    public function forceRadius(int $deviceId): bool
    {
        return $this->inList($deviceId, 'dhcp_force_radius');
    }

    public function forceTr101(int $deviceId): bool
    {
        return $this->inList($deviceId, 'dhcp_force_tr101');
    }

    /**
     * Je zařízení uvedené v daném nastavení? Formát: device ID oddělené novým
     * řádkem nebo čárkou. Trvale vynutí volbu pro konkrétní MK (optické brány)
     * nezávisle na ipoe flagu subnetu — aby načtení konfigurace ruční nastavení
     * nepřepsalo.
     */
    public function inList(int $deviceId, string $settingKey): bool
    {
        $raw = (string) Setting::get($settingKey, '');
        if ($raw === '') {
            return false;
        }
        foreach (preg_split('/\r\n|\r|\n|,/', $raw) as $token) {
            if (trim($token) === (string) $deviceId) {
                return true;
            }
        }
        return false;
    }
}
