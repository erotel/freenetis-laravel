<?php

namespace Tests\Feature\Contracts;

use App\Models\ContractAddon;
use App\Services\Contracts\PdfService;
use App\Services\ContractService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Dodatek ke smlouvě „přípojné místo" (type='connection_point'): vytvoření ze
 * placeného místa (allowed_subnets), náhled PDF a podpisový token.
 */
class ConnectionPointAddonTest extends DatabaseTestCase
{
    private const MEGA = 18; // cena 400

    private ContractService $svc;
    private int $member;
    private int $contractId;
    private int $allowedSubnetId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ContractService::class);

        // Reálný člen (FK allowed_subnets→members).
        $this->member = (int) DB::table('members')->where('id', '>', 1)->orderBy('id')->value('id');

        // Placené místo s rychlostí Mega (cena 400).
        $subnetId = DB::table('subnets')->insertGetId(['name' => 'NET test-cp']);
        $this->allowedSubnetId = DB::table('allowed_subnets')->insertGetId([
            'member_id' => $this->member, 'subnet_id' => $subnetId,
            'speed_class_id' => self::MEGA, 'charged' => 1, 'enabled' => 1,
        ]);

        // Podepsaná smlouva + party (contracts DB).
        $this->contractId = DB::connection('contracts')->table('contracts')->insertGetId([
            'member_id' => $this->member, 'contract_no' => 'SML-TEST-CP', 'status' => 'signed',
            'phone' => '777123456', 'created_at' => '2026-01-01 00:00:00',
        ]);
        DB::connection('contracts')->table('contract_parties')->insert([
            'contract_id' => $this->contractId, 'full_name' => 'Test Účastník',
            'street' => 'Testovací 1', 'town' => 'Prostějov', 'country' => 'ČR',
            'variable_symbol' => '999999', 'email' => 'test@example.com', 'birthday' => '1990-01-01',
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }

    public function test_vytvori_dodatek_ze_placeneho_mista(): void
    {
        $addon = $this->svc->createConnectionPointAddon($this->contractId, $this->allowedSubnetId, 'add', 'Testovací 1, Prostějov');

        $this->assertNotNull($addon);
        $this->assertSame('connection_point', $addon->type);
        $this->assertSame('Testovací 1, Prostějov', $addon->service_name);
        $this->assertSame('Mega', $addon->place_speed_name);
        $megaPrice = (float) DB::table('speed_classes')->where('id', self::MEGA)->value('price');
        $this->assertEqualsWithDelta($megaPrice, (float) $addon->service_price, 0.001, 'cena = cena rychlosti Mega');
        $this->assertSame('add', $addon->service_action);
        $this->assertSame('draft', $addon->status);
        $this->assertNotNull($addon->effective_date);
    }

    public function test_adresa_pripojeni_jako_nazev_mista(): void
    {
        $addon = $this->svc->createConnectionPointAddon($this->contractId, $this->allowedSubnetId, 'add', 'Tylova 38, Prostějov 79601');
        $this->assertSame('Tylova 38, Prostějov 79601', $addon->service_name, 'v dodatku je adresa připojení, ne podsíť');
    }

    public function test_nahled_pdf_se_vygeneruje(): void
    {
        $addon = $this->svc->createConnectionPointAddon($this->contractId, $this->allowedSubnetId, 'add', 'Testovací 1, Prostějov');
        $bytes = app(PdfService::class)->renderConnectionPointAddonPdf($addon, true);

        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_podpisovy_token_round_trip(): void
    {
        $addon = $this->svc->createConnectionPointAddon($this->contractId, $this->allowedSubnetId, 'add', 'Testovací 1, Prostějov');

        $res = $this->svc->issueConnectionPointAddonLink($addon->id);
        $this->assertNotNull($res['url']);

        parse_str((string) parse_url($res['url'], PHP_URL_QUERY), $q);
        $token = $q['t'] ?? '';
        $this->assertNotSame('', $token);
        $this->assertSame($addon->id, $this->svc->verifyConnectionPointAddonToken($token));
    }

    public function test_getter_a_seznam_filtruji_typ(): void
    {
        $addon = $this->svc->createConnectionPointAddon($this->contractId, $this->allowedSubnetId, 'add', 'Testovací 1, Prostějov');

        $this->assertSame($addon->id, $this->svc->getConnectionPointAddon($addon->id)?->id);
        $this->assertTrue($this->svc->connectionPointAddons($this->contractId)->contains('id', $addon->id));
        // service-addon getter typ neplete
        $this->assertNull($this->svc->getServiceAddon($addon->id));
    }

    public function test_neexistujici_misto_nevytvori_dodatek(): void
    {
        $this->assertNull($this->svc->createConnectionPointAddon($this->contractId, 2147480000, 'add', 'Adresa'));
    }

    public function test_bez_adresy_dodatek_nevznikne(): void
    {
        $this->assertNull(
            $this->svc->createConnectionPointAddon($this->contractId, $this->allowedSubnetId, 'add', ''),
            'bez adresy připojení nelze dodatek vytvořit'
        );
    }
}
