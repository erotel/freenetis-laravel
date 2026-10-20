<?php

namespace Tests\Characterization\Access;

use App\Domains\Access\Actions\DetectLineIdAnomaliesAction;
use App\Domains\Access\Actions\ReconcileLineIdsAction;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace self-learning line-id (dřív LineIdSyncService) před přesunem do
 * domény Access. `line_ids` je kontrakt pro RADIUS views (IPoE Framed-IP) a Kea
 * → chování párování a detekce anomálií musí refaktor zachovat beze změny.
 *
 * Běží nad reálnou DB v transakci: počty z reconcileFromSeen() porovnáváme jako
 * ROZDÍL proti ustálenému běhu (reálná nespárovaná data se počítají v každém běhu).
 * Kea flush je vypnutý (žádné uzly ani socket) → při změně rezervací vrací false.
 */
class LineIdSyncTest extends DatabaseTestCase
{
    private ReconcileLineIdsAction $reconcile;
    private int $deviceId;
    private string $tag;
    /** @var array<string,int> */
    private array $baseline;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kea.control_nodes' => [], 'kea.control_socket' => '']);
        $this->reconcile = app(ReconcileLineIdsAction::class);
        $this->tag = strtoupper(substr(md5(uniqid('', true)), 0, 6));
        $this->deviceId = (int) DB::table('devices')->insertGetId(['name' => 'char-lineid', 'type' => 1]);

        $this->reconcile->handle();               // ustálit reálná data (v transakci)
        $this->baseline = $this->reconcile->handle();
    }

    private function iface(string $mac): int
    {
        return (int) DB::table('ifaces')->insertGetId([
            'type' => 1, 'device_id' => $this->deviceId, 'mac' => $mac, 'name' => 'char',
        ]);
    }

    /** Unikátní Huawei circuit-id (parser: vendor huawei, device_ident = hostname). */
    private function circuit(int $port = 1): string
    {
        return "GigabitEthernet0/0/{$port}:999.0 CH{$this->tag}/0/0/0/0/0";
    }

    private function hex(string $s): string
    {
        return '0x' . bin2hex($s);
    }

    private function seen(string $circuit, string $mac, ?string $remote = null, ?string $at = null): int
    {
        return (int) DB::table('line_id_seen')->insertGetId([
            'circuit_id_hex' => $this->hex($circuit),
            'remote_id_hex'  => $remote === null ? null : $this->hex($remote),
            'mac'            => $mac,
            'seen_count'     => 1,
            'reconciled'     => 0,
            'last_seen'      => $at ?? now(),
        ]);
    }

    private function line(string $circuit, int $ifaceId, string $remote = ''): int
    {
        return (int) DB::table('line_ids')->insertGetId([
            'circuit_id' => $circuit, 'remote_id' => $remote, 'iface_id' => $ifaceId,
            'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function reconcile(): array
    {
        $r = $this->reconcile->handle();
        foreach (['reconciled', 'unmatched', 'conflicts', 'shared'] as $k) {
            $r[$k] -= $this->baseline[$k];
        }
        return $r;
    }

    private function seenRow(int $id): object
    {
        return DB::table('line_id_seen')->where('id', $id)->first();
    }

    private function mac(int $n): string
    {
        return sprintf('02:C4:%s:%s:%s:%02X', substr($this->tag, 0, 2), substr($this->tag, 2, 2), substr($this->tag, 4, 2), $n);
    }

    // ── reconcileFromSeen ────────────────────────────────────────────────────

    public function test_novy_port_se_znamou_mac_vytvori_line_id(): void
    {
        $iface = $this->iface($this->mac(1));
        $seen  = $this->seen($this->circuit(), strtolower($this->mac(1)));

        $r = $this->reconcile();

        $this->assertSame(1, $r['reconciled']);
        $this->assertFalse($r['cache_flushed'], 'změna rezervací → pokus o flush (bez uzlů = false)');
        $line = DB::table('line_ids')->where('circuit_id', $this->circuit())->first();
        $this->assertNotNull($line);
        $this->assertSame($iface, (int) $line->iface_id);
        $this->assertSame('', (string) $line->remote_id);
        $this->assertSame('huawei', $line->vendor);
        $this->assertSame("CH{$this->tag}", $line->device_ident);
        $this->assertSame('GigabitEthernet0/0/1', $line->port);
        $this->assertSame('accounting', $line->source);
        $this->assertSame(1, (int) $this->seenRow($seen)->reconciled);
    }

    public function test_stejny_zakaznik_na_svem_portu_jen_refresh(): void
    {
        $iface  = $this->iface($this->mac(1));
        $lineId = $this->line($this->circuit(), $iface);
        $seen   = $this->seen($this->circuit(), $this->mac(1), null, '2026-10-01 12:00:00');

        $r = $this->reconcile();

        $this->assertSame(1, $r['reconciled']);
        $line = DB::table('line_ids')->where('id', $lineId)->first();
        $this->assertSame('2026-10-01 12:00:00', (string) $line->last_seen);
        $this->assertSame('huawei', $line->vendor, 'prázdný parse se doplní');
        $this->assertSame(1, (int) $this->seenRow($seen)->reconciled);
    }

    public function test_konflikt_jiny_zakaznik_na_portu_nepremapuje(): void
    {
        $owner    = $this->iface($this->mac(1));
        $this->iface($this->mac(2));
        $lineId   = $this->line($this->circuit(), $owner);
        $seen     = $this->seen($this->circuit(), $this->mac(2));

        $r = $this->reconcile();

        $this->assertSame(1, $r['conflicts']);
        $this->assertSame($owner, (int) DB::table('line_ids')->where('id', $lineId)->value('iface_id'));
        $this->assertSame(0, (int) $this->seenRow($seen)->reconciled, 'konflikt zůstává pro detektor');
    }

    public function test_neznama_mac_je_onboarding_kandidat(): void
    {
        $seen = $this->seen($this->circuit(), $this->mac(9));

        $r = $this->reconcile();

        $this->assertSame(1, $r['unmatched']);
        $this->assertSame(0, (int) $this->seenRow($seen)->reconciled);
        $this->assertFalse(DB::table('line_ids')->where('circuit_id', $this->circuit())->exists());
    }

    public function test_sdileny_circuit_dve_mac_neulozi_a_smaze_line_id(): void
    {
        $a = $this->iface($this->mac(1));
        $this->iface($this->mac(2));
        $this->line($this->circuit(), $a);
        $s1 = $this->seen($this->circuit(), $this->mac(1));
        $s2 = $this->seen($this->circuit(), $this->mac(2));

        $r = $this->reconcile();

        $this->assertFalse(DB::table('line_ids')->where('circuit_id', $this->circuit())->exists(), 'sdílený port nesmí být identita');
        $this->assertSame(1, (int) $this->seenRow($s1)->reconciled);
        $this->assertSame(1, (int) $this->seenRow($s2)->reconciled);
        $this->assertSame(2, $r['shared']);
        $this->assertFalse($r['cache_flushed']);
    }

    public function test_vlanif_circuit_je_sdileny(): void
    {
        $this->iface($this->mac(1));
        $circuit = "0180.0000.{$this->tag}:Vlanif180";
        $seen    = $this->seen($circuit, $this->mac(1));

        $r = $this->reconcile();

        $this->assertSame(1, $r['shared']);
        $this->assertFalse(DB::table('line_ids')->where('circuit_id', $circuit)->exists());
        $this->assertSame(1, (int) $this->seenRow($seen)->reconciled);
    }

    public function test_remote_id_odlisi_stejny_circuit_na_dvou_switchich(): void
    {
        // DCN/TP-Link: stejný circuit-id, jiný switch (remote-id) → 2 různé identity.
        $circuit = "Vlan325+Ethernet1/0/{$this->tag}";
        $a = $this->iface($this->mac(1));
        $b = $this->iface($this->mac(2));
        $this->seen($circuit, $this->mac(1), "\x00\x03SW-A{$this->tag}");
        $this->seen($circuit, $this->mac(2), "\x00\x03SW-B{$this->tag}");

        $r = $this->reconcile();

        $this->assertSame(2, $r['reconciled']);
        $this->assertSame(0, $r['shared']);
        $rows = DB::table('line_ids')->where('circuit_id', $circuit)->orderBy('iface_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([$a, $b], $rows->pluck('iface_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('dcn', $rows[0]->vendor);
        // DCN parser nedá device_ident → bere se z remote-id (hex).
        $this->assertSame('0x' . strtoupper(bin2hex("\x00\x03SW-A{$this->tag}")), $rows[0]->device_ident);
    }

    public function test_bez_zmeny_rezervaci_se_kea_neflushuje(): void
    {
        $this->assertNull($this->baseline['cache_flushed'] ?? null, 'ustálený běh nemění rezervace');
        $this->assertNull($this->reconcile()['cache_flushed']);
    }

    // ── detectAnomalies ──────────────────────────────────────────────────────

    private function anomaly(string $circuit, string $mac): ?object
    {
        return DB::table('line_id_anomalies')->where('circuit_id', $circuit)->where('seen_mac', $mac)->first();
    }

    public function test_identity_cross_cizi_registrovana_mac_na_portu(): void
    {
        $owner = $this->iface($this->mac(1));
        $other = $this->iface($this->mac(2));
        $this->line($this->circuit(), $owner);
        $this->seen($this->circuit(), $this->mac(2));

        app(DetectLineIdAnomaliesAction::class)->handle();

        $a = $this->anomaly($this->circuit(), $this->mac(2));
        $this->assertNotNull($a);
        $this->assertSame('identity_cross', $a->type);
        $this->assertSame('critical', $a->severity);
        $this->assertSame($owner, (int) $a->expected_iface_id);
        $this->assertSame($other, (int) $a->seen_iface_id);
        $this->assertSame(1, (int) $a->seen_count);
    }

    public function test_unknown_device_neznama_mac_na_znamem_portu(): void
    {
        $owner = $this->iface($this->mac(1));
        $this->line($this->circuit(), $owner);
        $this->seen($this->circuit(), $this->mac(9));

        app(DetectLineIdAnomaliesAction::class)->handle();

        $a = $this->anomaly($this->circuit(), $this->mac(9));
        $this->assertSame('unknown_device', $a->type);
        $this->assertSame('warning', $a->severity);
        $this->assertNull($a->seen_iface_id);
    }

    public function test_mac_moved_registrovana_mac_na_cizim_portu(): void
    {
        $iface = $this->iface($this->mac(1));
        $this->line($this->circuit(1), $iface);           // domovský port
        $this->seen($this->circuit(2), $this->mac(1));    // viděna jinde (port bez mapování)

        app(DetectLineIdAnomaliesAction::class)->handle();

        $a = $this->anomaly($this->circuit(2), $this->mac(1));
        $this->assertSame('mac_moved', $a->type);
        $this->assertSame('high', $a->severity);
        $this->assertNull($a->expected_iface_id);
    }

    public function test_opakovana_detekce_zvysi_pocet_a_resi_znovuotevreni(): void
    {
        $owner = $this->iface($this->mac(1));
        $this->line($this->circuit(), $owner);
        $this->seen($this->circuit(), $this->mac(9), null, now()->subHour());

        app(DetectLineIdAnomaliesAction::class)->handle();
        // Vyřešeno PO posledním výskytu → zůstává vyřešené, jen count roste.
        DB::table('line_id_anomalies')->where('circuit_id', $this->circuit())->update(['resolved_at' => now()]);
        app(DetectLineIdAnomaliesAction::class)->handle();
        $a = $this->anomaly($this->circuit(), $this->mac(9));
        $this->assertSame(2, (int) $a->seen_count);
        $this->assertNotNull($a->resolved_at);

        // Nový výskyt PO vyřešení → znovuotevřít.
        DB::table('line_id_seen')->where('mac', $this->mac(9))->update(['last_seen' => now()->addMinute()]);
        app(DetectLineIdAnomaliesAction::class)->handle();
        $this->assertNull($this->anomaly($this->circuit(), $this->mac(9))->resolved_at);
    }

    public function test_sdileny_circuit_se_neflaguje_a_stare_zaznamy_se_ignoruji(): void
    {
        $owner = $this->iface($this->mac(1));
        $this->line($this->circuit(1), $owner);
        $this->seen($this->circuit(1), $this->mac(8));
        $this->seen($this->circuit(1), $this->mac(9));                       // 2 MAC = sdílený
        $this->line($this->circuit(2), $owner);
        $this->seen($this->circuit(2), $this->mac(7), null, now()->subDays(8)); // mimo okno 7 dní

        app(DetectLineIdAnomaliesAction::class)->handle();

        $this->assertFalse(DB::table('line_id_anomalies')->whereIn('circuit_id', [$this->circuit(1), $this->circuit(2)])->exists());
    }
}
