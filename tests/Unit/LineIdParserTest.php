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
        // ident OLT do device_ident, celá xpon cesta (vč. ONT) do port
        $this->assertSame('F47960E73E46', $p['device_ident']);
        $this->assertSame('xpon 0/2/0/8:38.1.1', $p['port']);
    }

    public function test_gpon_two_olts_same_port_distinguished_by_ident(): void
    {
        // 2 OLT za stejným DHCP serverem (10.133.0.16), stejné číslo PON portu →
        // odliší je ident OLT (device_ident); port sám o sobě by kolidoval.
        $a = $this->svc->parseCircuitId('F47960E73E46 xpon 0/2/0/10:11.1.1');
        $b = $this->svc->parseCircuitId('AABBCCDDEEFF xpon 0/2/0/10:11.1.1');
        $this->assertSame('F47960E73E46', $a['device_ident']);
        $this->assertSame('AABBCCDDEEFF', $b['device_ident']);
        $this->assertNotSame($a['device_ident'], $b['device_ident']);
        $this->assertSame($a['port'], $b['port']); // stejný port, rozliší jen ident
    }

    public function test_gpon_two_onts_same_pon_port_distinguished_by_port(): void
    {
        // 2 ONT na stejném PON portu téhož OLT → odliší celá xpon cesta (ONT část)
        $a = $this->svc->parseCircuitId('F47960E73E46 xpon 0/2/0/10:11.1.1');
        $b = $this->svc->parseCircuitId('F47960E73E46 xpon 0/2/0/10:12.1.1');
        $this->assertNotSame($a['port'], $b['port']);
    }

    public function test_parses_tplink_binary_option82(): void
    {
        // TP-Link SG2008 per-port binární circuit-id (0x0004 <slot16> <port16>)
        $p = $this->svc->parseCircuitId(hex2bin('000400010003'));
        $this->assertSame('tplink', $p['vendor']);
        $this->assertSame('port 3', $p['port']);
        $this->assertSame('0x000400010003', $p['device_ident']);
        // per-port rozlišené (port 1 vs port 3)
        $a = $this->svc->parseCircuitId(hex2bin('000400010001'));
        $this->assertSame('port 1', $a['port']);
        $this->assertNotSame($a['device_ident'], $p['device_ident']);
    }

    public function test_generic_binary_shows_hex_not_garbage(): void
    {
        // jiná binárka (ne 0004, jiná délka) → unknown, ale device_ident čitelný hex
        $p = $this->svc->parseCircuitId(hex2bin('0A0B0C'));
        $this->assertSame('unknown', $p['vendor']);
        $this->assertSame('0x0A0B0C', $p['device_ident']);
        $this->assertNull($p['port']);
    }

    public function test_parses_mikrotik(): void
    {
        $p = $this->svc->parseCircuitId('Smer9 eth 0/4');
        $this->assertSame('mikrotik', $p['vendor']);
        $this->assertSame('Smer9', $p['device_ident']);
        $this->assertSame('eth 0/4', $p['port']);
    }

    public function test_parses_huawei_vlanif(): void
    {
        $p = $this->svc->parseCircuitId('0180.0000.c88d-833a-7770:Vlanif180');
        $this->assertSame('huawei', $p['vendor']);
        $this->assertSame('0180.0000.c88d-833a-7770', $p['device_ident']);
        $this->assertSame('Vlanif180', $p['port']);
    }

    public function test_unknown_with_colon_splits_best_effort(): void
    {
        // neznámý formát s ":" → vendor 'unknown', rozdělí na identitu:port
        $p = $this->svc->parseCircuitId('nejakySwitch:port42');
        $this->assertSame('unknown', $p['vendor']);
        $this->assertSame('nejakySwitch', $p['device_ident']);
        $this->assertSame('port42', $p['port']);
    }

    public function test_unknown_without_colon_keeps_raw(): void
    {
        // neznámý bez ":" → celý řetězec do device_ident, nikdy vše NULL
        $p = $this->svc->parseCircuitId('naprosto neznámý formát');
        $this->assertSame('unknown', $p['vendor']);
        $this->assertSame('naprosto neznámý formát', $p['device_ident']);
        $this->assertNull($p['port']);
    }

    public function test_shared_circuit_vlanif(): void
    {
        // relay/VLAN circuit-id (Vlanif) = sdílené celou VLAN → shared (bez DB)
        $this->assertTrue($this->svc->isSharedCircuit('0142.0000.b4fb-f980-14b0:Vlanif142'));
    }

    public function test_port_level_circuit_not_shared(): void
    {
        // port-level (snooping / MikroTik) bez circuitHex = není shared
        $this->assertFalse($this->svc->isSharedCircuit('GigabitEthernet0/0/12:339.0 K364/0/0/0/0/0'));
        $this->assertFalse($this->svc->isSharedCircuit('Smer9 eth 0/4'));
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
