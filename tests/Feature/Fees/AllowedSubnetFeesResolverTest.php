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
    private float $megaPrice;   // skutečná cena z DB (admin ji může měnit)
    private float $gigaPrice;

    protected function setUp(): void
    {
        parent::setUp();
        // Reálný člen (FK allowed_subnets→members) s rychlostí Giga (fallback cena)
        // a BEZ existujících placených míst (ať je baseline 0).
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

        // Ceny čteme z DB — admin je mění, nesmíme je mít natvrdo.
        $this->megaPrice = (float) DB::table('speed_classes')->where('id', self::MEGA)->value('price');
        $this->gigaPrice = (float) DB::table('speed_classes')->where('id', self::GIGA)->value('price');
        if ($this->megaPrice <= 0 || $this->gigaPrice <= 0) {
            $this->markTestSkipped('Mega/Giga nemají cenu');
        }
    }

    private function makeSubnet(): int
    {
        return DB::table('subnets')->insertGetId(['name' => 'test-fee']);
    }

    private function addPlace(?int $speedClassId, bool $charged, bool $enabled = true): int
    {
        return DB::table('allowed_subnets')->insertGetId([
            'member_id' => $this->member, 'subnet_id' => $this->makeSubnet(),
            'speed_class_id' => $speedClassId, 'charged' => $charged ? 1 : 0,
            'enabled' => $enabled ? 1 : 0,
        ]);
    }

    public function test_cena_z_vlastni_rychlosti_mista(): void
    {
        $this->assertSame(0.0, AllowedSubnetFeesResolver::total($this->member), 'nic charged = 0');
        $this->addPlace(self::MEGA, charged: true);
        $this->assertSame($this->megaPrice, AllowedSubnetFeesResolver::total($this->member));
    }

    public function test_zdedena_rychlost_se_neuctuje(): void
    {
        // speed_class_id NULL (zděděná rychlost) → nelze účtovat, i když charged=1.
        $this->addPlace(null, charged: true);
        $this->assertSame(0.0, AllowedSubnetFeesResolver::total($this->member));
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
        $this->assertSame($this->megaPrice, AllowedSubnetFeesResolver::total($this->member));
    }

    public function test_soucet_vic_mist_a_items(): void
    {
        $this->addPlace(self::MEGA, charged: true);  // cena Mega
        $this->addPlace(self::GIGA, charged: true);  // cena Giga
        $expected = $this->megaPrice + $this->gigaPrice;
        $this->assertSame($expected, AllowedSubnetFeesResolver::total($this->member));

        $items = AllowedSubnetFeesResolver::items($this->member);
        $this->assertCount(2, $items);
        $this->assertSame($expected, array_sum(array_column($items, 'fee')));
    }
}
