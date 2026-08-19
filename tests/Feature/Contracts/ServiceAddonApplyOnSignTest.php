<?php

namespace Tests\Feature\Contracts;

use App\Models\Fee;
use App\Models\MemberFee;
use App\Models\User;
use App\Services\AclService;
use App\Services\Contracts\PdfService;
use App\Services\ContractService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Apply-on-sign u dodatku „dodatečná služba" (2026-08-19): přidání služby
 * (poplatku) se členovi přiřadí AŽ po podpisu dodatku; zrušení se aplikuje
 * vydáním dodatku (bez podpisu). Přímé přiřazení dodatečné služby smluvnímu
 * členovi v poplatcích je zablokované.
 */
class ServiceAddonApplyOnSignTest extends DatabaseTestCase
{
    private ContractService $svc;
    private int $member;
    private int $contractId;
    private int $userId;
    private int $feeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ContractService::class);

        $fee = Fee::where('type_id', Fee::TYPE_ADDITIONAL_SERVICE)->where('archived', 0)->orderBy('id')->first();
        if (!$fee) {
            $this->markTestSkipped('Není žádný poplatek typu „additional service".');
        }
        $this->feeId = (int) $fee->id;

        $this->member = (int) DB::table('members')->where('id', '>', 1)->orderBy('id')->value('id');

        // Čistý stav: člen tuto službu zatím nemá (v transakci, rollbackne se).
        DB::table('members_fees')->where('member_id', $this->member)->where('fee_id', $this->feeId)->delete();

        $this->contractId = DB::connection('contracts')->table('contracts')->insertGetId([
            'member_id' => $this->member, 'contract_no' => 'SML-TEST-SVC', 'status' => 'signed',
            'phone' => '777123456', 'created_at' => '2026-01-01 00:00:00',
        ]);
        DB::connection('contracts')->table('contract_parties')->insert([
            'contract_id' => $this->contractId, 'full_name' => 'Test Účastník',
            'street' => 'Testovací 1', 'town' => 'Prostějov', 'country' => 'ČR',
            'variable_symbol' => '999999', 'email' => 'test@example.com', 'birthday' => '1990-01-01',
            'created_at' => '2026-01-01 00:00:00',
        ]);

        $this->userId = (int) DB::table('users')->insertGetId([
            'member_id' => $this->member, 'login' => 'svc_' . uniqid(), 'password' => Hash::make('x'),
            'type' => 1, 'application_password' => 'xxxxxxxx', 'settings' => '',
            'name' => 'Svc', 'surname' => 'Test', 'comment' => '',
        ]);
    }

    private function isFeeActive(): bool
    {
        $today = now()->toDateString();
        return MemberFee::where('member_id', $this->member)
            ->where('fee_id', $this->feeId)
            ->whereDate('activation_date', '<=', $today)
            ->whereDate('deactivation_date', '>=', $today)
            ->exists();
    }

    public function test_vytvoreni_add_dodatku_je_jen_navrh_neprirazuje(): void
    {
        $addon = $this->svc->createServiceAddon($this->contractId, $this->feeId, 'add');

        $this->assertNotNull($addon);
        $this->assertSame('additional_service', $addon->type);
        $this->assertSame('add', $addon->service_action);
        $this->assertSame($this->feeId, (int) $addon->fee_id);
        $this->assertSame('draft', $addon->status);

        $this->assertFalse($this->isFeeActive(), 'služba se při vytvoření dodatku ještě nepřiřadí');
    }

    public function test_podpis_add_dodatku_prirradi_sluzbu(): void
    {
        $addon = $this->svc->createServiceAddon($this->contractId, $this->feeId, 'add');
        $this->svc->signServiceAddon($addon, app(PdfService::class));

        $this->assertTrue($this->isFeeActive(), 'po podpisu je služba přiřazená (aktivní)');

        $applied = DB::connection('contracts')->table('contract_events')
            ->where('contract_id', $this->contractId)->where('event', 'service_addon_applied')->exists();
        $this->assertTrue($applied, 'podpis zaloguje service_addon_applied');
    }

    public function test_vydani_remove_dodatku_deaktivuje_sluzbu(): void
    {
        // Člen službu aktivní má.
        MemberFee::create([
            'member_id' => $this->member, 'fee_id' => $this->feeId,
            'activation_date' => now()->subMonth()->toDateString(), 'deactivation_date' => '9999-12-31',
            'priority' => 1, 'comment' => 'test',
        ]);
        $this->assertTrue($this->isFeeActive());

        $addon = $this->svc->createServiceAddon($this->contractId, $this->feeId, 'remove');
        $this->svc->issueServiceRemoval($addon, app(PdfService::class));

        $row = MemberFee::where('member_id', $this->member)->where('fee_id', $this->feeId)->first();
        $this->assertSame(now()->toDateString(), $row->deactivation_date?->toDateString(), 'zrušení nastaví konec služby na dnešek');

        $applied = DB::connection('contracts')->table('contract_events')
            ->where('contract_id', $this->contractId)->where('event', 'service_addon_removal_applied')->exists();
        $this->assertTrue($applied, 'vydání zaloguje service_addon_removal_applied');
    }

    public function test_prime_prirazeni_sluzby_smluvnimu_clenovi_je_zablokovane(): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true);

        $this->actingAs(User::find($this->userId))->post(route('members_fees.store', $this->member), [
            'fee_id'          => $this->feeId,
            'activation_date' => now()->toDateString(),
        ]);

        $exists = DB::table('members_fees')->where('member_id', $this->member)->where('fee_id', $this->feeId)->exists();
        $this->assertFalse($exists, 'dodatečnou službu nelze smluvnímu členovi přiřadit přímo (jen dodatkem)');
    }
}
