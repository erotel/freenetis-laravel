<?php

namespace Tests\Feature\Subnets;

use App\Domains\Access\Repositories\LineIdRepository;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Detekce sdílených line-idů pro výpis /subnets/{id} (LineIdRepository::
 * sharedCircuitsByMac). Sdílený circuit-id (týž hex vidí víc MAC → typicky
 * bytovka za 1 ONT bez L2 switche) se ve výpisu odliší žlutě „sdílený" od
 * červené „chybí". Viz [[project_lineid_tr101_gotcha]].
 */
class SubnetSharedLineIdTest extends DatabaseTestCase
{
    private Closure $shared;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shared = fn (array $macs) => app(LineIdRepository::class)->sharedCircuitsByMac($macs);
    }

    public function test_shared_detekovan_unikatni_ne(): void
    {
        $sharedCircuit = '__selftest shared circuit';   // 2 MAC → sdílený
        $uniqCircuit   = '__selftest unique circuit';   // 1 MAC → není
        $sharedHex = '0x' . bin2hex($sharedCircuit);
        $uniqHex   = '0x' . bin2hex($uniqCircuit);

        DB::table('line_id_seen')->insert([
            ['circuit_id_hex' => $sharedHex, 'mac' => 'AA:AA:AA:AA:AA:01', 'seen_count' => 1, 'reconciled' => 1, 'last_seen' => now()],
            ['circuit_id_hex' => $sharedHex, 'mac' => 'AA:AA:AA:AA:AA:02', 'seen_count' => 1, 'reconciled' => 1, 'last_seen' => now()],
            ['circuit_id_hex' => $uniqHex,   'mac' => 'AA:AA:AA:AA:AA:03', 'seen_count' => 1, 'reconciled' => 1, 'last_seen' => now()],
        ]);

        $out = ($this->shared)([
            'AA:AA:AA:AA:AA:01', 'AA:AA:AA:AA:AA:02', 'AA:AA:AA:AA:AA:03', 'AA:AA:AA:AA:AA:99',
        ]);

        // 2 MAC na sdíleném circuitu → jsou v mapě, vč. dekódovaného circuit-id
        $this->assertArrayHasKey('AA:AA:AA:AA:AA:01', $out);
        $this->assertArrayHasKey('AA:AA:AA:AA:AA:02', $out);
        $this->assertSame($sharedCircuit, $out['AA:AA:AA:AA:AA:01']);
        // unikátní circuit → NENÍ sdílený
        $this->assertArrayNotHasKey('AA:AA:AA:AA:AA:03', $out);
        // MAC bez záznamu v line_id_seen → NENÍ v mapě (= „chybí", ne „sdílený")
        $this->assertArrayNotHasKey('AA:AA:AA:AA:AA:99', $out);
    }

    public function test_prazdny_vstup(): void
    {
        $this->assertSame([], ($this->shared)([]));
    }
}
