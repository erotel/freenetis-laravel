<?php

namespace Tests\Feature\Contracts;

use App\Models\AllowedSubnet;
use App\Models\User;
use App\Services\AclService;
use App\Services\Contracts\PdfService;
use App\Services\ContractService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Apply-on-sign u dodatku „přípojné místo" (2026-08-19): zpoplatnění místa
 * (allowed_subnets.charged) se zapne AŽ po podpisu dodatku (add); zrušení
 * (charged=0) se aplikuje vydáním dodatku (remove). Přímé zapnutí účtování
 * smluvnímu členovi je zablokované.
 */
class ConnectionPointAddonApplyOnSignTest extends DatabaseTestCase
{
    private const MEGA = 18; // rychlost s cenou

    private ContractService $svc;
    private int $member;
    private int $contractId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ContractService::class);

        $this->member = (int) DB::table('members')->where('id', '>', 1)->orderBy('id')->value('id');

        $this->contractId = DB::connection('contracts')->table('contracts')->insertGetId([
            'member_id' => $this->member, 'contract_no' => 'SML-TEST-CP2', 'status' => 'signed',
            'phone' => '777123456', 'created_at' => '2026-01-01 00:00:00',
        ]);
        DB::connection('contracts')->table('contract_parties')->insert([
            'contract_id' => $this->contractId, 'full_name' => 'Test Účastník',
            'street' => 'Testovací 1', 'town' => 'Prostějov', 'country' => 'ČR',
            'variable_symbol' => '999999', 'email' => 'test@example.com', 'birthday' => '1990-01-01',
            'created_at' => '2026-01-01 00:00:00',
        ]);

        $this->userId = (int) DB::table('users')->insertGetId([
            'member_id' => $this->member, 'login' => 'cp_' . uniqid(), 'password' => Hash::make('x'),
            'type' => 1, 'application_password' => 'xxxxxxxx', 'settings' => '',
            'name' => 'Cp', 'surname' => 'Test', 'comment' => '',
        ]);
    }

    private function makePlace(bool $charged): int
    {
        $subnetId = DB::table('subnets')->insertGetId(['name' => 'NET cp-aos-' . uniqid()]);
        return (int) DB::table('allowed_subnets')->insertGetId([
            'member_id' => $this->member, 'subnet_id' => $subnetId,
            'speed_class_id' => self::MEGA, 'charged' => $charged ? 1 : 0, 'enabled' => 1,
        ]);
    }

    public function test_vytvoreni_add_dodatku_je_jen_navrh_nezpoplatnuje(): void
    {
        $asId  = $this->makePlace(false); // ještě neúčtované
        $addon = $this->svc->createConnectionPointAddon($this->contractId, $asId, 'add', 'Testovací 1, Prostějov');

        $this->assertNotNull($addon);
        $this->assertSame('connection_point', $addon->type);
        $this->assertSame('add', $addon->service_action);
        $this->assertSame($asId, (int) $addon->allowed_subnet_id);
        $this->assertSame('draft', $addon->status);

        $this->assertFalse((bool) AllowedSubnet::find($asId)->charged, 'místo se při vytvoření dodatku ještě nezpoplatní');
    }

    public function test_podpis_add_dodatku_zpoplatni_misto(): void
    {
        $asId  = $this->makePlace(false);
        $addon = $this->svc->createConnectionPointAddon($this->contractId, $asId, 'add', 'Testovací 1, Prostějov');
        $this->svc->signConnectionPointAddon($addon, app(PdfService::class));

        $this->assertTrue((bool) AllowedSubnet::find($asId)->charged, 'po podpisu se místo účtuje (charged=1)');

        $applied = DB::connection('contracts')->table('contract_events')
            ->where('contract_id', $this->contractId)->where('event', 'connection_point_addon_applied')->exists();
        $this->assertTrue($applied);
    }

    public function test_vydani_remove_dodatku_zrusi_uctovani(): void
    {
        $asId  = $this->makePlace(true); // účtované
        $addon = $this->svc->createConnectionPointAddon($this->contractId, $asId, 'remove', 'Testovací 1, Prostějov');
        $this->svc->issueConnectionPointRemoval($addon, app(PdfService::class));

        $this->assertFalse((bool) AllowedSubnet::find($asId)->charged, 'po vydání zrušení se místo neúčtuje (charged=0)');

        $applied = DB::connection('contracts')->table('contract_events')
            ->where('contract_id', $this->contractId)->where('event', 'connection_point_addon_removal_applied')->exists();
        $this->assertTrue($applied);
    }

    public function test_prime_zpoplatneni_smluvnimu_clenovi_je_zablokovane(): void
    {
        $asId = $this->makePlace(false);

        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true);

        $this->actingAs(User::find($this->userId))->put(route('allowed_subnets.update_billing', $asId), [
            'charged' => 1,
        ]);

        $this->assertFalse((bool) AllowedSubnet::find($asId)->charged, 'zpoplatnění místa nelze zapnout přímo u smluvního člena (jen dodatkem)');
    }
}
