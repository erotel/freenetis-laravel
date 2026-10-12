<?php

namespace Tests\Feature\Access;

use App\Domains\Access\Repositories\LineIdRepository;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Kontrakt `line_ids` (viz app/Domains/Access/INFRASTRUKTURA.md): identita
 * (circuit_id, remote_id) jsou SUROVÉ BAJTY option82 — VARBINARY. Remote-id bývá
 * binární MAC switche/OLT; RADIUS views ho párují přes HEX(username) a
 * *_kea4_v přes CONCAT(remote_id, circuit_id). Hlídá drift na VARCHAR.
 */
class LineIdBinaryIdentityTest extends DatabaseTestCase
{
    private LineIdRepository $repo;
    private int $ifaceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new LineIdRepository();
        $this->ifaceId = (int) DB::table('ifaces')->value('id');
        if (!$this->ifaceId) {
            $this->markTestSkipped('chybí rozhraní');
        }
    }

    private function line(string $circuit, string $remote): void
    {
        $this->repo->create([
            'circuit_id' => $circuit, 'remote_id' => $remote, 'iface_id' => $this->ifaceId, 'source' => 'manual',
        ]);
    }

    public function test_sloupce_jsou_varbinary_a_remote_not_null(): void
    {
        $cols = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())->where('table_name', 'line_ids')
            ->whereIn('column_name', ['circuit_id', 'remote_id'])
            ->pluck('column_type', 'column_name');
        $this->assertSame('varbinary(255)', $cols['circuit_id']);
        $this->assertSame('varbinary(255)', $cols['remote_id']);
        $this->assertSame('NO', DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())->where('table_name', 'line_ids')
            ->where('column_name', 'remote_id')->value('is_nullable'));
    }

    public function test_binarni_remote_id_projde_beze_zmeny(): void
    {
        $remote  = "\x00\x1a\xff\xc3\x28\x9e"; // MAC, není validní UTF-8
        $circuit = '__selftest_bin ' . uniqid();
        $this->line($circuit, $remote);

        $row = $this->repo->find($circuit, $remote);
        $this->assertNotNull($row);
        $this->assertSame(bin2hex($remote), bin2hex($row->remote_id));
        $this->assertSame(
            strtoupper(bin2hex($remote . $circuit)),
            DB::table('line_ids')->where('id', $row->id)->value(DB::raw('HEX(CONCAT(remote_id, circuit_id))'))
        );
    }

    /** Bajtové porovnání: jiná velikost písmen = jiný port (collation by je sloučila). */
    public function test_velikost_pismen_rozlisuje(): void
    {
        $circuit = '__selftest_case ' . uniqid();
        $this->line($circuit, 'sw-a');
        $this->line($circuit, 'SW-A');

        $this->assertSame(2, DB::table('line_ids')->where('circuit_id', $circuit)->count());
        $this->assertNull($this->repo->find(strtoupper($circuit), 'sw-a'));
    }
}
