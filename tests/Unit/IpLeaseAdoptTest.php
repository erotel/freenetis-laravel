<?php

namespace Tests\Unit;

use App\Http\Controllers\IpAddressController;
use App\Services\LineIdSyncService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Helper „převzít pool lease IP při registraci přípojky":
 *  - subnetIdForIp() vybere jen IPoE (dhcp+ipoe) subnet obsahující IP,
 *  - currentLeaseIp() odmítne nevalidní MAC bez síťového dotazu.
 * Čistá logika — bez DB/sítě (Kea control není v testu dostupné).
 */
class IpLeaseAdoptTest extends TestCase
{
    private function subnets(): Collection
    {
        // (network, netmask, dhcp, ipoe)
        $mk = fn ($id, $net, $mask, $dhcp, $ipoe) => (object) [
            'id' => $id, 'network_address' => $net, 'netmask' => $mask, 'dhcp' => $dhcp, 'ipoe' => $ipoe,
        ];
        return collect([
            $mk(1, '10.133.40.0', '255.255.255.0', 1, 1),  // IPoE optika (K364)
            $mk(2, '10.133.50.0', '255.255.255.0', 1, 0),  // DHCP ale ne IPoE
            $mk(3, '10.133.60.0', '255.255.255.0', 0, 1),  // IPoE ale ne DHCP
        ]);
    }

    private function callSubnetIdForIp(Collection $subnets, string $ip): ?int
    {
        $ctrl = (new \ReflectionClass(IpAddressController::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(IpAddressController::class, 'subnetIdForIp');
        $m->setAccessible(true);
        return $m->invoke($ctrl, $subnets, $ip);
    }

    public function test_picks_ipoe_subnet_containing_ip(): void
    {
        $this->assertSame(1, $this->callSubnetIdForIp($this->subnets(), '10.133.40.201'));
    }

    public function test_ignores_non_ipoe_and_non_dhcp_subnets(): void
    {
        // IP ve 2. (dhcp, ne-ipoe) ani 3. (ipoe, ne-dhcp) subnetu → nenabízet
        $this->assertNull($this->callSubnetIdForIp($this->subnets(), '10.133.50.10'));
        $this->assertNull($this->callSubnetIdForIp($this->subnets(), '10.133.60.10'));
    }

    public function test_ip_outside_all_subnets_is_null(): void
    {
        $this->assertNull($this->callSubnetIdForIp($this->subnets(), '10.199.199.199'));
    }

    public function test_current_lease_ip_rejects_invalid_mac(): void
    {
        // nevalidní MAC se nesmí dostat k síťovému dotazu → null
        $this->assertNull((new LineIdSyncService())->currentLeaseIp('neplatna'));
        $this->assertNull((new LineIdSyncService())->currentLeaseIp(''));
    }
}
