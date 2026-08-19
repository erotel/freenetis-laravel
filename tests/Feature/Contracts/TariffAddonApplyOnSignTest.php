<?php

namespace Tests\Feature\Contracts;

use App\Models\User;
use App\Services\Contracts\PdfService;
use App\Services\ContractService;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Apply-on-sign u tarifního dodatku (2026-08-19): změna tarifu se má aplikovat
 * na člena (a tím i do účtování) AŽ po podpisu dodatku, ne hned při editaci.
 *
 *  - createTariffChangeAddon() vytvoří jen NÁVRH (member.speed_class_id se nemění,
 *    cílový tarif se uloží do new_speed_class_id).
 *  - signTariffAddon() teprve propíše nový tarif na člena + zaloguje
 *    'tariff_addon_applied'.
 *  - MemberController::update() u člena s podepsanou smlouvou přímou změnu
 *    rychlosti ignoruje (tarif se mění jen dodatkem) — i při podvrhu POSTem.
 */
class TariffAddonApplyOnSignTest extends DatabaseTestCase
{
    private const SPEED_OLD = 5;   // Standard (320)
    private const SPEED_NEW = 18;  // Mega (420)

    private ContractService $svc;
    private int $member;
    private int $contractId;
    private int $userId;
    private string $memberName;
    private int $memberType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ContractService::class);

        $m = DB::table('members')
            ->where('id', '>', 1)
            ->where('locked', 0)
            ->whereNotNull('name')
            ->whereIn('type', array_keys(\App\Helpers\MemberType::labels()))
            ->orderBy('id')
            ->first(['id', 'name', 'type']);

        $this->member     = (int) $m->id;
        $this->memberName = $m->name;
        $this->memberType = (int) $m->type;

        DB::table('members')->where('id', $this->member)
            ->update(['speed_class_id' => self::SPEED_OLD]);

        $this->contractId = DB::connection('contracts')->table('contracts')->insertGetId([
            'member_id' => $this->member, 'contract_no' => 'SML-TEST-TARIFF', 'status' => 'signed',
            'phone' => '777123456', 'created_at' => '2026-01-01 00:00:00',
        ]);
        DB::connection('contracts')->table('contract_parties')->insert([
            'contract_id' => $this->contractId, 'full_name' => 'Test Účastník',
            'street' => 'Testovací 1', 'town' => 'Prostějov', 'country' => 'ČR',
            'variable_symbol' => '999999', 'email' => 'test@example.com', 'birthday' => '1990-01-01',
            'speed_name' => 'Standard', 'price' => 320, 'created_at' => '2026-01-01 00:00:00',
        ]);

        $this->userId = (int) DB::table('users')->insertGetId([
            'member_id'            => $this->member,
            'login'                => 'tariff_' . uniqid(),
            'password'             => Hash::make('x'),
            'type'                 => 1,
            'application_password' => 'xxxxxxxx',
            'settings'             => '',
            'name'                 => 'Tariff', 'surname' => 'Test', 'comment' => '',
        ]);
    }

    /** Vše povoleno včetně qos_ceil — jediné, co změnu blokuje, je podepsaná smlouva. */
    private function grantAllAcl(): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true);
    }

    private function updateMember(int $speedClassId)
    {
        return $this->actingAs(User::find($this->userId))->put(
            route('members.update', $this->member),
            [
                'name'           => $this->memberName,
                'type'           => $this->memberType,
                'speed_class_id' => $speedClassId,
            ]
        );
    }

    public function test_vytvoreni_dodatku_je_jen_navrh_nemeni_clena(): void
    {
        $addon = $this->svc->createTariffChangeAddon($this->contractId, self::SPEED_NEW);

        $this->assertNotNull($addon);
        $this->assertSame('tariff_change', $addon->type);
        $this->assertSame('draft', $addon->status);
        $this->assertSame(self::SPEED_NEW, (int) $addon->new_speed_class_id, 'cílový tarif se uloží jako návrh');
        $this->assertSame('Mega', $addon->new_speed_name);
        $this->assertSame('Standard', $addon->old_speed_name);

        // Klíčové: rychlost člena se NESMÍ změnit už při vytvoření návrhu.
        $this->assertSame(
            self::SPEED_OLD,
            (int) DB::table('members')->where('id', $this->member)->value('speed_class_id'),
            'rychlost člena se při vytvoření dodatku nemění (jen návrh)'
        );
    }

    public function test_podpis_dodatku_aplikuje_tarif_na_clena(): void
    {
        $addon = $this->svc->createTariffChangeAddon($this->contractId, self::SPEED_NEW);
        $this->svc->signTariffAddon($addon, app(PdfService::class));

        $this->assertSame(
            self::SPEED_NEW,
            (int) DB::table('members')->where('id', $this->member)->value('speed_class_id'),
            'po podpisu dodatku má člen nový tarif'
        );

        $applied = DB::connection('contracts')->table('contract_events')
            ->where('contract_id', $this->contractId)
            ->where('event', 'tariff_addon_applied')
            ->exists();
        $this->assertTrue($applied, 'podpis zaloguje tariff_addon_applied');

        $this->assertSame('signed', $addon->fresh()->status);
        $this->assertSame(now()->toDateString(), $addon->fresh()->effective_date?->toDateString(), 'účinnost = den podpisu');
    }

    public function test_editace_clena_se_smlouvou_ignoruje_podvrzenou_rychlost(): void
    {
        $this->grantAllAcl();

        $this->updateMember(self::SPEED_NEW);

        $this->assertSame(
            self::SPEED_OLD,
            (int) DB::table('members')->where('id', $this->member)->value('speed_class_id'),
            'u člena s podepsanou smlouvou nesmí přímá editace změnit tarif (jen dodatkem)'
        );
    }

    public function test_editace_clena_bez_smlouvy_rychlost_zmeni(): void
    {
        // Smlouva není podepsaná → přímá editace tarifu má fungovat jako dřív.
        DB::connection('contracts')->table('contracts')
            ->where('id', $this->contractId)->update(['status' => 'draft']);

        $this->grantAllAcl();

        $this->updateMember(self::SPEED_NEW);

        $this->assertSame(
            self::SPEED_NEW,
            (int) DB::table('members')->where('id', $this->member)->value('speed_class_id'),
            'bez podepsané smlouvy se rychlost edituje přímo'
        );
    }
}
