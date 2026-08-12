<?php

namespace Tests\Feature\Fees;

use App\Services\AllowedSubnetFeesResolver;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Platba per přípojné místo: součet cen placených (charged) a zapnutých míst
 * člena; cena = fee_override ?? cena rychlosti místa ?? cena rychlosti člena.
 */
class AllowedSubnetFeesResolverTest extends DatabaseTestCase
{
    private const MEGA = 18; // cena 400
    private const GIGA = 17; // cena 800

    private int $member;

    protected function setUp(): void
    {
        parent::setUp();
        // Reálný člen (FK allowed_subnets→members) s rychlostí Giga (fallback cena
        // 800) a BEZ existujících placených míst (ať je baseline 0).
        $this->member = (int) DB::table('members')
            ->where('speed_class_id', self::GIGA)->where('id', '>', 1)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('allowed_subnets')
                    ->whereColumn('allowed_subnets.member_id', 'members.id')
                    ->where('charged', 1);
            })
            ->value('id');
        if (!$this->member) {
            $this->markTestSkipped('žádný člen s rychlostí Giga bez placených míst');
        }
    }

    private function makeSubnet(): int
    {
        return DB::table('subnets')->insertGetId(['name' => 'test-fee']);
    }

    private function addPlace(?int $speedClassId, bool $charged, ?float $override = null, bool $enabled = true): int
    {
        return DB::table('allowed_subnets')->insertGetId([
            'member_id' => $this->member, 'subnet_id' => $this->makeSubnet(),
            'speed_class_id' => $speedClassId, 'charged' => $charged ? 1 : 0,
            'fee_override' => $override, 'enabled' => $enabled ? 1 : 0,
        ]);
    }

    public function test_cena_z_rychlosti_mista(): void
    {
        $this->assertSame(0.0, AllowedSubnetFeesResolver::total($this->member), 'nic charged = 0');
        $this->addPlace(self::MEGA, charged: true);
        $this->assertSame(400.0, AllowedSubnetFeesResolver::total($this->member));
    }

    public function test_override_ma_prednost(): void
    {
        $this->addPlace(self::MEGA, charged: true, override: 150.0);
        $this->assertSame(150.0, AllowedSubnetFeesResolver::total($this->member));
    }

    public function test_bez_vlastni_rychlosti_pouzije_cenu_clena(): void
    {
        // speed_class_id NULL → zdědí rychlost člena (Giga, 800).
        $this->addPlace(null, charged: true);
        $this->assertSame(800.0, AllowedSubnetFeesResolver::total($this->member));
    }

    public function test_neuctovane_se_nepocita(): void
    {
        $this->addPlace(self::MEGA, charged: false); // charged=0 → nic
        $this->assertSame(0.0, AllowedSubnetFeesResolver::total($this->member));
    }

    public function test_vypnute_ale_charged_se_uctuje(): void
    {
        // Vypnutí (enabled=0) NEobchází platbu — charged rozhoduje.
        $this->addPlace(self::MEGA, charged: true, enabled: false);
        $this->assertSame(400.0, AllowedSubnetFeesResolver::total($this->member));
    }

    public function test_soucet_vic_mist_a_items(): void
    {
        $this->addPlace(self::MEGA, charged: true);                 // 400
        $this->addPlace(self::GIGA, charged: true, override: 100.0); // 100
        $this->assertSame(500.0, AllowedSubnetFeesResolver::total($this->member));

        $items = AllowedSubnetFeesResolver::items($this->member);
        $this->assertCount(2, $items);
        $this->assertSame(500.0, array_sum(array_column($items, 'fee')));
    }
}
