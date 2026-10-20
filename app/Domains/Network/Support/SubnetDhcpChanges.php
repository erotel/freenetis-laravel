<?php

namespace App\Domains\Network\Support;

use App\Domains\Access\Events\IpoeReservationsChanged;
use App\Models\Subnet;

/**
 * Změna v podsíti pro DHCP servery: `dhcp_expired` (sdílený příznak pro legacy
 * jediného konzumenta) + `dhcp_changed_at` (detekce per klient při víc DHCP
 * serverech). Sem vedou všechny cesty změny IP (IP / rozhraní / zařízení / člen).
 */
final class SubnetDhcpChanges
{
    /** Podsíť změněna → DHCP servery si stáhnou novou konfiguraci; u IPoE i Kea host-cache. */
    public static function expire(Subnet $subnet): void
    {
        $subnet->update(['dhcp_expired' => 1, 'dhcp_changed_at' => now()]);

        // Změna IP v IPoE subnetu = změna rezervace → Kea host-cache (odloženě,
        // jednou za request).
        if ($subnet->dhcp && $subnet->ipoe) {
            IpoeReservationsChanged::dispatch("subnet:{$subnet->id}", true);
        }
    }

    /** Export stažen DHCP serverem → příznak změny shodit. */
    public static function synced(Subnet $subnet): void
    {
        $subnet->update(['dhcp_expired' => 0]);
    }
}
