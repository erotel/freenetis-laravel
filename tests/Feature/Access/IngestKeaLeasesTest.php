<?php

namespace Tests\Feature\Access;

use App\Domains\Access\Actions\DetectLineIdAnomaliesAction;
use App\Domains\Access\Actions\IngestKeaLeasesAction;
use App\Domains\Access\Kea\KeaControlClient;
use App\Domains\Access\Repositories\LineIdRepository;
use App\Domains\Access\Support\RelayAgentInfo;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Pozorování MAC na IPoE portech z leasů Kea → `line_id_seen` (Kea segmenty nemají
 * RADIUS accounting, bez toho je detektor anomálií slepý). Option82 z
 * `user-context.ISC.relay-agent-info.sub-options` (store-extended-info).
 */
class IngestKeaLeasesTest extends DatabaseTestCase
{
    private const CIRCUIT = 'GigabitEthernet0/0/3:339.0 K364-TEST/0/0/0/0/0';
    private const REMOTE  = "\x80\xb5\x75\x85\x3a\xd0";

    private function subOptions(?string $circuit = self::CIRCUIT, string $remote = self::REMOTE): string
    {
        $tlv = '';
        if ($circuit !== null) {
            $tlv .= chr(1) . chr(strlen($circuit)) . $circuit;
        }
        $tlv .= chr(2) . chr(strlen($remote)) . $remote;
        return '0x' . strtoupper(bin2hex($tlv)); // Kea vrací velká hex písmena
    }

    private function lease(string $mac, int $cltt, ?string $sub = null): array
    {
        return [
            'ip-address' => '10.133.40.212', 'hw-address' => $mac, 'state' => 0, 'cltt' => $cltt,
            'user-context' => ['ISC' => ['relay-agent-info' => ['sub-options' => $sub ?? $this->subOptions()]]],
        ];
    }

    private function kea(?array ...$runs): void
    {
        $m = $this->mock(KeaControlClient::class);
        $m->shouldReceive('activeLeases4')->andReturn(...$runs);
    }

    private function seen(string $mac): ?object
    {
        return DB::table('line_id_seen')->where('circuit_id_hex', '0x' . bin2hex(self::CIRCUIT))->where('mac', $mac)->first();
    }

    // ── parser ───────────────────────────────────────────────────────────────

    public function test_parser_rozdeli_circuit_a_remote(): void
    {
        $r = (new RelayAgentInfo())->parseSubOptions($this->subOptions());
        $this->assertSame(self::CIRCUIT, $r['circuit']);
        $this->assertSame(self::REMOTE, $r['remote']);
    }

    public function test_parser_odmitne_useknute_a_nesmyslne_tlv(): void
    {
        $p = new RelayAgentInfo();
        $this->assertSame(['circuit' => null, 'remote' => null], $p->parseSubOptions('0x0129474967'));
        $this->assertSame(['circuit' => null, 'remote' => null], $p->parseSubOptions('nesmysl'));
        $this->assertSame(['circuit' => null, 'remote' => null], $p->parseSubOptions(null));
    }

    // ── ingest ───────────────────────────────────────────────────────────────

    public function test_lease_zapise_pozorovani_ve_formatu_freeradius(): void
    {
        $this->kea([$this->lease('ac:ab:aa:11:22:33', 1794215000)]);

        $r = app(IngestKeaLeasesAction::class)->handle();

        $this->assertSame(['ok' => true, 'leases' => 1, 'recorded' => 1, 'without_option82' => 0], $r);
        $row = $this->seen('AC:AB:AA:11:22:33');
        $this->assertNotNull($row, 'MAC velkými písmeny jako User-Name z MikroTiku');
        $this->assertSame('0x80b575853ad0', $row->remote_id_hex);
        $this->assertSame(0, (int) $row->reconciled);
        $this->assertSame(1, (int) $row->seen_count);
    }

    public function test_stejny_lease_opakovane_nenafukuje_pocitadlo_novejsi_ano(): void
    {
        $this->kea(
            [$this->lease('AC:AB:AA:11:22:33', 1794215000)],
            [$this->lease('AC:AB:AA:11:22:33', 1794215000)],
            [$this->lease('AC:AB:AA:11:22:33', 1794216000)]
        );
        $a = app(IngestKeaLeasesAction::class);

        $a->handle();
        $this->assertSame(0, $a->handle()['recorded'], 'stejný lease se při dalším syncu nezapočítá');
        $this->assertSame(1, (int) $this->seen('AC:AB:AA:11:22:33')->seen_count);

        $this->assertSame(1, $a->handle()['recorded']);
        $this->assertSame(2, (int) $this->seen('AC:AB:AA:11:22:33')->seen_count);
    }

    public function test_lease_bez_option82_preskoci(): void
    {
        $l = $this->lease('AC:AB:AA:11:22:33', 1794215000);
        unset($l['user-context']);
        $this->kea([$l]);

        $r = app(IngestKeaLeasesAction::class)->handle();

        $this->assertSame(1, $r['without_option82']);
        $this->assertSame(0, $r['recorded']);
    }

    public function test_nedostupna_kea_nic_nezapise(): void
    {
        $this->kea(null);
        $before = DB::table('line_id_seen')->count();

        $this->assertFalse(app(IngestKeaLeasesAction::class)->handle()['ok']);
        $this->assertSame($before, DB::table('line_id_seen')->count());
    }

    public function test_cizi_mac_na_znamem_portu_detektor_nahlasi(): void
    {
        // Známý port (line_ids) patřící registrované přípojce; na něm se objeví
        // neregistrovaná MAC (výměna routeru / druhé zařízení) → unknown_device.
        $ifaceId = (int) DB::table('ifaces')->whereNotNull('mac')->value('id');
        if (!$ifaceId) {
            $this->markTestSkipped('žádné rozhraní s MAC');
        }
        app(LineIdRepository::class)->create([
            'circuit_id' => self::CIRCUIT, 'remote_id' => self::REMOTE, 'iface_id' => $ifaceId,
            'source' => 'accounting', 'last_seen' => now(),
        ]);
        $this->kea([$this->lease('AC:AB:AA:11:22:33', now()->timestamp)]);

        app(IngestKeaLeasesAction::class)->handle();
        app(DetectLineIdAnomaliesAction::class)->handle();

        $a = DB::table('line_id_anomalies')->where('seen_mac', 'AC:AB:AA:11:22:33')->first();
        $this->assertNotNull($a, 'detektor musí z Kea pozorování poznat cizí MAC na portu');
        $this->assertSame('unknown_device', $a->type);
        $this->assertSame($ifaceId, (int) $a->expected_iface_id);
    }
}
