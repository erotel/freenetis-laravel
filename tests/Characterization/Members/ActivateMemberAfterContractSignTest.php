<?php

namespace Tests\Characterization\Members;

use App\Domains\Members\Actions\ActivateMemberAfterContractSignAction;
use App\Helpers\MemberType;
use App\Services\PendingCustomerRedirectService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace aktivace člena po online podpisu smlouvy (vytaženo beze změny
 * chování z PublicSignController::finalizeContract).
 */
class ActivateMemberAfterContractSignTest extends DatabaseTestCase
{
    private int $apId;
    private $redirects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        if (!$this->apId) {
            $this->markTestSkipped('chybí adresní bod');
        }
        $this->redirects = $this->mock(PendingCustomerRedirectService::class);
    }

    private function member(int $type): int
    {
        return (int) DB::table('members')->insertGetId([
            'name' => 'Char Podpis', 'type' => $type, 'entrance_date' => null,
            'leaving_date' => '9999-12-31', 'registration' => 0, 'locked' => 0,
            'address_point_id' => $this->apId,
        ]);
    }

    private function activate(int $id): ?array
    {
        return app(ActivateMemberAfterContractSignAction::class)->handle($id);
    }

    private function row(int $id): object
    {
        return DB::table('members')->where('id', $id)->first();
    }

    public function test_cekajici_zakaznik_se_aktivuje_a_uvolni_presmerovani(): void
    {
        $id = $this->member(MemberType::PENDING_CUSTOMER);
        $this->redirects->shouldReceive('refreshForMember')->once()->with($id);

        $this->assertSame(['old_type' => 18, 'new_type' => 2], $this->activate($id));
        $this->assertSame(MemberType::CUSTOMER, (int) $this->row($id)->type);
        $this->assertSame(1, (int) $this->row($id)->registration);
    }

    public function test_zadatel_se_aktivuje_na_zakaznika(): void
    {
        $id = $this->member(MemberType::APPLICANT);
        $this->redirects->shouldReceive('refreshForMember')->once()->with($id);

        $this->assertSame(['old_type' => 1, 'new_type' => 2], $this->activate($id));
        $this->assertSame(MemberType::CUSTOMER, (int) $this->row($id)->type);
    }

    public function test_cekajici_clen_jen_registration_typ_beze_zmeny(): void
    {
        $id = $this->member(MemberType::PENDING_MEMBER);
        $this->redirects->shouldNotReceive('refreshForMember');

        $this->assertSame(['old_type' => 17, 'new_type' => 17], $this->activate($id));
        $this->assertSame(MemberType::PENDING_MEMBER, (int) $this->row($id)->type);
        $this->assertSame(1, (int) $this->row($id)->registration);
    }

    public function test_aktivni_zakaznik_jen_registration(): void
    {
        $id = $this->member(MemberType::CUSTOMER);
        $this->redirects->shouldNotReceive('refreshForMember');

        $this->assertSame(['old_type' => 2, 'new_type' => 2], $this->activate($id));
        $this->assertSame(1, (int) $this->row($id)->registration);
    }

    public function test_neexistujici_clen_null(): void
    {
        $this->redirects->shouldNotReceive('refreshForMember');
        $this->assertNull($this->activate(PHP_INT_MAX >> 33));
    }
}
