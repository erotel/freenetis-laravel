<?php

namespace App\Domains\Network\Support;

use App\Domains\Network\Support\AllowedSubnetSyncService;
use App\Domains\Notifications\Actions\SyncPendingCustomerRedirectAction;
use App\Models\Subnet;

/**
 * Dopady přidání / odebrání IP člena v podsítích:
 *  - DHCP podsíť → dhcp_expired (DHCP server si přegeneruje konfiguraci)
 *  - povolené podsítě člena (allowed_subnets) přidat / odebrat
 *  - čekajícímu zákazníkovi (18) hned aktivovat přesměrování (ne až cronem)
 */
class SubnetAssignmentSync
{
    public function __construct(private AllowedSubnetSyncService $allowed)
    {
    }

    /** @param int[] $subnetIds */
    public function expireDhcp(array $subnetIds): void
    {
        foreach ($subnetIds as $sid) {
            if ($subnet = Subnet::find($sid)) {
                SubnetDhcpChanges::expire($subnet);
            }
        }
    }

    /** @param int[] $subnetIds */
    public function ipsAdded(?int $memberId, array $subnetIds): void
    {
        if (!$memberId) {
            return;
        }
        foreach ($subnetIds as $sid) {
            $this->allowed->onIpAdded($memberId, (int) $sid);
        }
        if (!empty($subnetIds)) {
            app(SyncPendingCustomerRedirectAction::class)->handle($memberId);
        }
    }

    /** @param int[] $subnetIds povolené podsítě odebrat, jen když v nich člen nemá jinou IP */
    public function ipsRemoved(?int $memberId, array $subnetIds): void
    {
        if (!$memberId) {
            return;
        }
        foreach ($subnetIds as $sid) {
            $this->allowed->onIpRemoved($memberId, (int) $sid);
        }
    }

    /** Zařízení změnilo majitele: nový podsítě získá (v limitu), starý je ztratí bez jiné IP. */
    public function ownerChanged(?int $oldMemberId, ?int $newMemberId, array $subnetIds): void
    {
        if (!$newMemberId || $oldMemberId === $newMemberId || !$subnetIds) {
            return;
        }
        foreach ($subnetIds as $sid) {
            $this->allowed->onIpAdded($newMemberId, (int) $sid);
        }
        if ($oldMemberId) {
            foreach ($subnetIds as $sid) {
                $this->allowed->onIpRemoved($oldMemberId, (int) $sid);
            }
        }
    }
}
