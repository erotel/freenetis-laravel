<?php

namespace Tests\Feature\Contracts;

use App\Domains\Acl\AclService;
use App\Domains\Contracts\Queries\ConnectionPointHintsQuery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Nápověda na kartě smlouvy: proč místo nejde vybrat do dodatku a varování k
 * situaci člena (vypnuté účtované místo, rychlost bez dodatku, jiná adresa).
 */
class ConnectionPointHintsTest extends DatabaseTestCase
{
    private int $memberId;
    private int $userId;
    private int $contractId;
    private int $speedId;
    private int $ipSeq = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u) => (int) ($u->id ?? $u) !== $this->userId);
        $acl->shouldReceive('flushUserCache')->andReturnNull();

        $this->speedId = (int) DB::table('speed_classes')->insertGetId(['name' => 'Char Místo', 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => 444]);
        $this->memberId = (int) DB::table('members')->insertGetId([
            'name' => 'Char Místa', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31', 'organization_identifier' => '', 'vat_organization_identifier' => '',
        ]);
        $this->userId = (int) DB::table('users')->insertGetId(['member_id' => $this->memberId, 'login' => 'charmist_' . uniqid(), 'password' => Hash::make('x'),
            'type' => User::MAIN_USER, 'application_password' => 'xxxxxxxx', 'settings' => '', 'name' => 'Char', 'surname' => 'Místa', 'comment' => '', 'birthday' => '1985-04-03']);
        $this->contractId = (int) DB::connection('contracts')->table('contracts')->insertGetId([
            'member_id' => $this->memberId, 'contract_no' => 'SML-CHAR-MISTA-' . uniqid(), 'status' => 'signed', 'phone' => '+420777123456', 'created_at' => now(),
        ]);
    }

    private function place(string $name, ?int $speed, bool $charged, bool $enabled = true): int
    {
        return (int) DB::table('allowed_subnets')->insertGetId([
            'member_id' => $this->memberId, 'subnet_id' => (int) DB::table('subnets')->insertGetId(['name' => $name]),
            'speed_class_id' => $speed, 'charged' => (int) $charged, 'enabled' => (int) $enabled,
        ]);
    }

    private function addon(int $placeId, string $status, string $address, string $action = 'add', int $no = 1): void
    {
        DB::connection('contracts')->table('contract_addons')->insert([
            'contract_id' => $this->contractId, 'type' => 'connection_point', 'addon_no' => $no, 'service_name' => $address, 'service_action' => $action,
            'allowed_subnet_id' => $placeId, 'effective_date' => now()->toDateString(), 'status' => $status,
        ]);
    }

    /** Zařízení člena s IP v podsíti místa a adresou. */
    private function device(int $placeId, string $number): string
    {
        $town   = DB::table('towns')->first();
        $street = (int) DB::table('streets')->insertGetId(['street' => 'Mistni' . uniqid()]);
        $ap     = (int) DB::table('address_points')->insertGetId(['town_id' => $town->id, 'street_id' => $street, 'street_number' => $number, 'country_id' => 1]);
        $device = (int) DB::table('devices')->insertGetId(['user_id' => $this->userId, 'name' => 'Char', 'type' => 1, 'address_point_id' => $ap]);
        $iface  = (int) DB::table('ifaces')->insertGetId(['device_id' => $device, 'name' => 'eth0', 'type' => 3]);
        DB::table('ip_addresses')->insert(['iface_id' => $iface, 'subnet_id' => DB::table('allowed_subnets')->where('id', $placeId)->value('subnet_id'),
            'ip_address' => '10.254.254.' . $this->ipSeq++]);

        return \App\Domains\Billing\Fees\AllowedSubnetFeesResolver::deviceAddress($this->memberId, (int) DB::table('allowed_subnets')->where('id', $placeId)->value('subnet_id'));
    }

    private function hints(): array
    {
        return app(ConnectionPointHintsQuery::class)->forMember($this->memberId, $this->contractId);
    }

    public function test_misto_bez_vlastni_rychlosti_je_uvedene_s_duvodem(): void
    {
        $id = $this->place('NET bez rychlosti', null, false);
        $this->place('NET k výběru', $this->speedId, false);

        $this->assertSame([['id' => $id, 'name' => 'NET bez rychlosti']], $this->hints()['unavailable']);
    }

    public function test_varuje_vypnute_uctovane_misto(): void
    {
        $this->place('NET vypnuté', $this->speedId, true, false);
        $this->place('NET zapnuté', $this->speedId, true);

        $warnings = $this->hints()['warnings'];
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('NET vypnuté', $warnings[0]);
        $this->assertStringContainsString('444', $warnings[0]);
    }

    public function test_varuje_rychlost_v_qos_pred_podpisem(): void
    {
        $id = $this->place('NET čeká', $this->speedId, false);
        $this->place('NET bez dodatku', $this->speedId, false);
        $this->place('NET vypnuté', $this->speedId, false, false);
        $this->addon($id, 'draft', 'Někde 1', 'add', 2);

        $warnings = $this->hints()['warnings'];
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('NET čeká', $warnings[0]);
        $this->assertStringContainsString('dodatku č. 2', $warnings[0]);
        $this->assertStringContainsString('NET bez dodatku', $warnings[1]);
        $this->assertStringContainsString('nemá dodatek čekající na podpis', $warnings[1]);
    }

    public function test_varuje_jinou_adresu_v_dodatku(): void
    {
        $jina = $this->place('NET jiná', $this->speedId, true);
        $this->device($jina, '158');
        $this->addon($jina, 'signed', 'ev. č. 11, Určice 79804');

        // shoda nezávisí na velikosti písmen a mezerách
        $stejna = $this->place('NET stejná', $this->speedId, true);
        $adresa = $this->device($stejna, '159');
        $this->addon($stejna, 'signed', '  ' . mb_strtoupper($adresa) . ' ');

        // bez zařízení není s čím porovnat, zrušení se neporovnává, zrušené místo také ne
        $this->addon($this->place('NET bez zařízení', $this->speedId, true), 'signed', 'Jinde 1');
        $zruseni = $this->place('NET zrušení', $this->speedId, true);
        $this->device($zruseni, '160');
        $this->addon($zruseni, 'draft', 'Jinde 2', 'remove');
        $staré = $this->place('NET staré', null, false);
        $this->device($staré, '161');
        $this->addon($staré, 'signed', 'Jinde 3');

        $warnings = $this->hints()['warnings'];
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('ev. č. 11, Určice 79804', $warnings[0]);
        $this->assertStringContainsString('158', $warnings[0]);
    }

    public function test_porovnava_nejnovejsi_dodatek_mista(): void
    {
        $id = $this->place('NET opravené', $this->speedId, true);
        $adresa = $this->device($id, '158');
        $this->addon($id, 'signed', 'Stará adresa 1', 'add', 1);
        $this->addon($id, 'signed', $adresa, 'add', 2);

        $this->assertSame([], $this->hints()['warnings']);
    }

    public function test_zakaznik_napovedu_nevidi_a_spravce_ano(): void
    {
        $this->place('NET bez rychlosti', null, false);
        $this->place('NET vypnuté', $this->speedId, true, false);

        $this->actingAs(User::find((int) DB::table('users')->where('type', 1)->value('id')))
            ->get(route('contracts.show', $this->memberId))->assertOk()
            ->assertSee('NET bez rychlosti')->assertSee('NET vypnuté je vypnuté')
            ->assertSee(route('allowed_subnets.by_member', $this->memberId));

        $this->actingAs(User::find($this->userId))
            ->get(route('contracts.show', $this->memberId))->assertOk()
            ->assertDontSee('NET bez rychlosti')->assertDontSee('je vypnuté');
    }
}
