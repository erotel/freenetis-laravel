<?php

namespace Tests\Characterization\Members;

use App\Domains\Members\Actions\ActivateMemberAfterContractSignAction;
use App\Helpers\MemberType;
use App\Domains\Notifications\Actions\SyncPendingCustomerRedirectAction;
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
        $this->redirects = $this->mock(SyncPendingCustomerRedirectAction::class);
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
        $this->redirects->shouldReceive('handle')->once()->with($id);

        $this->assertSame(['old_type' => 18, 'new_type' => 2], $this->activate($id));
        $this->assertSame(MemberType::CUSTOMER, (int) $this->row($id)->type);
        $this->assertSame(1, (int) $this->row($id)->registration);
        $this->assertSame(now()->toDateString(), (string) $this->row($id)->entrance_date);
        $this->assertSame(now()->toDateString(), (string) $this->row($id)->entrance_form_accepted);
    }

    public function test_zadatel_se_aktivuje_na_zakaznika(): void
    {
        $id = $this->member(MemberType::APPLICANT);
        $this->redirects->shouldReceive('handle')->once()->with($id);

        $this->assertSame(['old_type' => 1, 'new_type' => 2], $this->activate($id));
        $this->assertSame(MemberType::CUSTOMER, (int) $this->row($id)->type);
    }

    public function test_cekajici_clen_jen_registration_typ_beze_zmeny(): void
    {
        $id = $this->member(MemberType::PENDING_MEMBER);
        $this->redirects->shouldNotReceive('handle');

        $this->assertSame(['old_type' => 17, 'new_type' => 17], $this->activate($id));
        $this->assertSame(MemberType::PENDING_MEMBER, (int) $this->row($id)->type);
        $this->assertSame(1, (int) $this->row($id)->registration);
        $this->assertNull($this->row($id)->entrance_date);   // 17 čeká na ruční schválení
    }

    public function test_aktivni_zakaznik_jen_registration(): void
    {
        $id = $this->member(MemberType::CUSTOMER);
        $this->redirects->shouldNotReceive('handle');

        DB::table('members')->where('id', $id)->update(['entrance_date' => '2024-03-01']);

        $this->assertSame(['old_type' => 2, 'new_type' => 2], $this->activate($id));
        $this->assertSame(1, (int) $this->row($id)->registration);
        $this->assertSame('2024-03-01', (string) $this->row($id)->entrance_date);   // bez změny typu nesahat
    }

    public function test_aktivace_zapise_approved_do_auditu_bez_emailu(): void
    {
        $id = $this->member(MemberType::PENDING_CUSTOMER);
        $this->redirects->shouldReceive('handle');
        $before = DB::table('email_queues')->count();

        $this->activate($id);

        $log = DB::table('audit_logs')->where('auditable_type', 'members')
            ->where('auditable_id', $id)->where('action', 'approved')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->user_id);   // online podpis = bez přihlášeného uživatele
        $this->assertSame(['type' => 2], json_decode($log->new_values, true));
        $this->assertSame($before, DB::table('email_queues')->count());
    }

    public function test_bez_zmeny_typu_zadny_approved(): void
    {
        $id = $this->member(MemberType::PENDING_MEMBER);
        $this->activate($id);
        $this->assertFalse(DB::table('audit_logs')->where('auditable_type', 'members')
            ->where('auditable_id', $id)->where('action', 'approved')->exists());
    }

    public function test_neexistujici_clen_null(): void
    {
        $this->redirects->shouldNotReceive('handle');
        $this->assertNull($this->activate(PHP_INT_MAX >> 33));
    }
}
