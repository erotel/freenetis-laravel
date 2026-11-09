<?php

namespace Tests\Feature\Access;

use App\View\Queries\MenuBadgesQuery;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Číslo u „Anomálie line-id" v menu = všechny OTEVŘENÉ anomálie, ne jen kritické.
 * Dřív se počítaly jen critical (identity_cross), takže výměna routeru
 * (unknown_device = warning) ukazovala v menu 0, i když anomálie existovala.
 */
class LineIdAnomalyMenuBadgeTest extends DatabaseTestCase
{
    private function anomaly(string $type, string $severity, bool $resolved = false): void
    {
        DB::table('line_id_anomalies')->insert([
            'circuit_id' => 'badge-test-' . uniqid(), 'seen_mac' => 'AA:BB:CC:00:00:01',
            'type' => $type, 'severity' => $severity, 'seen_count' => 1,
            'first_seen' => now(), 'last_seen' => now(),
            'resolved_at' => $resolved ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_pocita_vsechny_otevrene_bez_ohledu_na_zavaznost(): void
    {
        $q = app(MenuBadgesQuery::class);
        $before = $q->openLineIdAnomalies();

        $this->anomaly('unknown_device', 'warning');
        $this->anomaly('mac_moved', 'high');
        $this->anomaly('identity_cross', 'critical');
        $this->anomaly('unknown_device', 'warning', resolved: true);

        $this->assertSame($before + 3, $q->openLineIdAnomalies(), 'vyřešené se nepočítají, ostatní všechny');
    }
}
