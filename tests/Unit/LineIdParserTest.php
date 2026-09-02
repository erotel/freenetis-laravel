<?php

namespace Tests\Unit;

use App\Services\LineIdSyncService;
use Tests\TestCase;

/**
 * Parser IPoE line-id (option82 circuit-id) — 4 vendor formáty + hex decode.
 * Čistá logika bez DB (parseCircuitId / decodeHex jsou public).
 */
class LineIdParserTest extends TestCase
{
    private LineIdSyncService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new LineIdSyncService();
    }

    public function test_parses_huawei(): void
    {
        $p = $this->svc->parseCircuitId('GigabitEthernet0/0/12:339.0 K364/0/0/0/0/0');
        $this->assertSame('huawei', $p['vendor']);
        $this->assertSame('K364', $p['device_ident']);
        $this->assertSame('GigabitEthernet0/0/12', $p['port']);
    }

    public function test_parses_dcn(): void
    {
        $p = $this->svc->parseCircuitId('Vlan325+Ethernet1/0/13');
        $this->assertSame('dcn', $p['vendor']);
        $this->assertSame('Ethernet1/0/13', $p['port']);
    }

    public function test_parses_gpon(): void
    {
        $p = $this->svc->parseCircuitId('F47960E73E46 xpon 0/2/0/8:38.1.1');
        $this->assertSame('gpon', $p['vendor']);
        $this->assertSame('xpon 0/2/0/8', $p['port']);
    }

    public function test_parses_mikrotik(): void
    {
        $p = $this->svc->parseCircuitId('Smer9 eth 0/4');
        $this->assertSame('mikrotik', $p['vendor']);
        $this->assertSame('Smer9', $p['device_ident']);
        $this->assertSame('eth 0/4', $p['port']);
    }

    public function test_unknown_returns_nulls(): void
    {
        $p = $this->svc->parseCircuitId('naprosto neznámý formát');
        $this->assertNull($p['vendor']);
        $this->assertNull($p['port']);
    }

    public function test_decode_hex(): void
    {
        // 'A/1' = 0x412f31
        $this->assertSame('A/1', $this->svc->decodeHex('0x412f31'));
        $this->assertSame('A/1', $this->svc->decodeHex('412f31'));
        $this->assertNull($this->svc->decodeHex('xyz'));        // ne-hex
        $this->assertNull($this->svc->decodeHex('abc'));        // lichá délka
        $this->assertNull($this->svc->decodeHex(''));
    }
}
