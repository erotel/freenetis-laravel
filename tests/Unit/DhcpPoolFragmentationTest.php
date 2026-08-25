<?php

namespace Tests\Unit;

use App\Http\Controllers\DeviceController;
use Closure;
use Tests\TestCase;

/**
 * Fragmentovaný DHCP/PPPoE pool: `DeviceController::fragmentPoolRanges()` poskládá
 * ranges z intervalu s vyříznutými registrovanými statikami (subnet minus
 * alokované IP). Čistá logika bez DB — private metoda přes Closure::bind.
 *
 * Motivace: PPPoE `/ip pool` nezná statické leasy ani RADIUS Framed-IP → registrovaná
 * IP musí fyzicky chybět v ranges, jinak by ji PPPoE bootstrap přidělil znovu (konflikt).
 */
class DhcpPoolFragmentationTest extends TestCase
{
    private Closure $fragment;

    protected function setUp(): void
    {
        parent::setUp();
        $ctrl = (new \ReflectionClass(DeviceController::class))->newInstanceWithoutConstructor();
        $this->fragment = Closure::bind(
            fn (int $s, int $e, array $ex) => $this->fragmentPoolRanges($s, $e, $ex),
            $ctrl,
            DeviceController::class
        );
    }

    /** @param string[] $excluded @return string[] */
    private function frag(string $start, string $end, array $excluded): array
    {
        return ($this->fragment)(
            ip2long($start),
            ip2long($end),
            array_map('ip2long', $excluded)
        );
    }

    public function test_bez_vyloucenych_je_jeden_souvisly_rozsah(): void
    {
        $this->assertSame(
            ['10.133.31.18-10.133.31.30'],
            $this->frag('10.133.31.18', '10.133.31.30', [])
        );
    }

    /** Přesně příklad z konzultace: .23 a .26 registrované → tři rozsahy s dírami. */
    public function test_diry_po_registrovanych_statikach(): void
    {
        $this->assertSame(
            [
                '10.133.31.18-10.133.31.22',
                '10.133.31.24-10.133.31.25',
                '10.133.31.27-10.133.31.30',
            ],
            $this->frag('10.133.31.18', '10.133.31.30', ['10.133.31.23', '10.133.31.26'])
        );
    }

    public function test_vylouceni_na_zacatku_posune_start(): void
    {
        $this->assertSame(
            ['10.133.31.19-10.133.31.30'],
            $this->frag('10.133.31.18', '10.133.31.30', ['10.133.31.18'])
        );
    }

    public function test_vylouceni_na_konci_zkrati_end(): void
    {
        $this->assertSame(
            ['10.133.31.18-10.133.31.29'],
            $this->frag('10.133.31.18', '10.133.31.30', ['10.133.31.30'])
        );
    }

    public function test_sousedni_vylouceni_neudelaji_prazdny_rozsah(): void
    {
        $this->assertSame(
            ['10.133.31.18-10.133.31.22', '10.133.31.25-10.133.31.30'],
            $this->frag('10.133.31.18', '10.133.31.30', ['10.133.31.23', '10.133.31.24'])
        );
    }

    public function test_vsechno_alokovane_vrati_prazdno(): void
    {
        // Malý interval .18-.20, všechny tři obsazené → žádná volná adresa.
        $this->assertSame(
            [],
            $this->frag('10.133.31.18', '10.133.31.20', ['10.133.31.18', '10.133.31.19', '10.133.31.20'])
        );
    }

    public function test_vylouceni_mimo_interval_se_ignoruji(): void
    {
        // .40 a .5 jsou mimo [.18,.30] → nemají vliv, duplicitní .23 se sjednotí.
        $this->assertSame(
            ['10.133.31.18-10.133.31.22', '10.133.31.24-10.133.31.30'],
            $this->frag('10.133.31.18', '10.133.31.30', ['10.133.31.40', '10.133.31.5', '10.133.31.23', '10.133.31.23'])
        );
    }

    public function test_prazdny_interval_start_vetsi_nez_end(): void
    {
        $this->assertSame([], $this->frag('10.133.31.30', '10.133.31.18', []));
    }
}
