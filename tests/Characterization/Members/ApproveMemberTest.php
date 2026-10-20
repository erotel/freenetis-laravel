<?php

namespace Tests\Characterization\Members;

use App\Domains\Acl\AclService;
use App\Helpers\MemberType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace POST members/{id}/approve (tlačítko „Schválit“ u čekatele).
 *
 * Zachycuje SOUČASNÉ chování před refaktorem do App\Domains\Members — včetně
 * podivností (viz testy *_quirk). Při refaktoru se aserce nemění; změna chování
 * = samostatný commit, který test vědomě upraví.
 */
class ApproveMemberTest extends DatabaseTestCase
{
    private int $adminId;
    private int $apId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->apId    = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        if (!$this->adminId || !$this->apId) {
            $this->markTestSkipped('chybí hlavní uživatel / adresní bod');
        }
        $this->allowAcl(true);
    }

    private function allowAcl(bool $allow): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn($allow);
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function member(int $type, bool $registration, ?string $email = null): int
    {
        $id = (int) DB::table('members')->insertGetId([
            'name'             => 'Char Schválení',
            'type'             => $type,
            'entrance_date'    => null,
            'leaving_date'     => '9999-12-31',
            'registration'     => $registration ? 1 : 0,
            'locked'           => 0,
            'address_point_id' => $this->apId,
        ]);
        if ($email !== null) {
            $uid = (int) DB::table('users')->insertGetId([
                'member_id'            => $id,
                'login'                => 'char_' . uniqid(),
                'password'             => Hash::make('x'),
                'type'                 => 1,
                'application_password' => 'xxxxxxxx',
                'settings'             => '',
                'name'                 => 'Char', 'surname' => 'Test', 'comment' => '',
            ]);
            $cid = (int) DB::table('contacts')->insertGetId(['type' => 20, 'value' => $email]);
            DB::table('users_contacts')->insert(['user_id' => $uid, 'contact_id' => $cid]);
        }
        return $id;
    }

    private function approve(int $id)
    {
        return $this->actingAs(User::find($this->adminId))
            ->from(route('members.show', $id))
            ->post(route('members.approve', $id));
    }

    private function row(int $id): object
    {
        return DB::table('members')->where('id', $id)->first();
    }

    private function queued(string $to)
    {
        return DB::table('email_queues')->where('to', $to)->get();
    }

    public function test_cekajici_clen_se_schvali_na_radneho_a_dostane_email(): void
    {
        $email = 'char_' . uniqid() . '@example.test';
        $id    = $this->member(MemberType::PENDING_MEMBER, true, $email);

        $this->approve($id)
            ->assertRedirect(route('members.show', $id))
            ->assertSessionHas('success', 'Člen byl schválen.');

        $m = $this->row($id);
        $this->assertSame(MemberType::REGULAR, (int) $m->type);
        $this->assertSame(now()->toDateString(), (string) $m->entrance_form_accepted);

        $mails = $this->queued($email);
        $this->assertCount(1, $mails);
        $tpl    = DB::table('messages')->where('id', 10)->first();
        $prefix = \App\Models\Setting::get('email_subject_prefix', '');
        $this->assertSame(($prefix ? $prefix . ' :: ' : '') . $tpl->name, $mails[0]->subject);
        $this->assertSame(\App\Models\Setting::get('email_default_email', 'noreply@pvfree.net'), $mails[0]->from);
        $this->assertSame(0, (int) $mails[0]->state);
        $this->assertNotSame('', trim((string) $mails[0]->body));
    }

    public function test_cekajici_zakaznik_se_schvali_na_zakaznika(): void
    {
        $email = 'char_' . uniqid() . '@example.test';
        $id    = $this->member(MemberType::PENDING_CUSTOMER, true, $email);

        $this->approve($id)->assertRedirect(route('members.show', $id));

        $m = $this->row($id);
        $this->assertSame(MemberType::CUSTOMER, (int) $m->type);
        $this->assertSame(now()->toDateString(), (string) $m->entrance_form_accepted);

        // Zákazníkům se uvítací e-mail neposílá (mají e-mail se smlouvou po podpisu).
        $this->assertCount(0, $this->queued($email));
    }

    public function test_bez_emailoveho_kontaktu_se_schvali_bez_emailu(): void
    {
        $before = DB::table('email_queues')->count();
        $id     = $this->member(MemberType::PENDING_MEMBER, true);

        $this->approve($id)->assertRedirect(route('members.show', $id));

        $this->assertSame(MemberType::REGULAR, (int) $this->row($id)->type);
        $this->assertSame($before, DB::table('email_queues')->count());
    }

    public function test_neni_cekatel_vrati_chybu_a_nic_nezmeni(): void
    {
        $id = $this->member(MemberType::CUSTOMER, true);

        $this->approve($id)
            ->assertRedirect(route('members.show', $id))
            ->assertSessionHas('error', 'Člen není čekatel.');

        $m = $this->row($id);
        $this->assertSame(MemberType::CUSTOMER, (int) $m->type);
        $this->assertNull($m->entrance_form_accepted);
    }

    public function test_bez_podepsane_prihlasky_vrati_chybu_a_nic_nezmeni(): void
    {
        $id = $this->member(MemberType::PENDING_MEMBER, false);

        $this->approve($id)
            ->assertRedirect(route('members.show', $id))
            ->assertSessionHas('error', 'Přihláška/smlouva není podepsána.');

        $this->assertSame(MemberType::PENDING_MEMBER, (int) $this->row($id)->type);
    }

    public function test_bez_prava_403(): void
    {
        $this->allowAcl(false);
        $id = $this->member(MemberType::PENDING_MEMBER, true);

        $this->approve($id)->assertForbidden();
        $this->assertSame(MemberType::PENDING_MEMBER, (int) $this->row($id)->type);
    }

    public function test_neexistujici_clen_404(): void
    {
        $this->approve(PHP_INT_MAX >> 33)->assertNotFound();
    }

    public function test_zmena_typu_se_zapise_do_auditu_pres_model(): void
    {
        $id = $this->member(MemberType::PENDING_MEMBER, true);
        $this->approve($id);

        $this->assertTrue(DB::table('audit_logs')
            ->where('auditable_type', 'members')
            ->where('auditable_id', $id)
            ->where('action', 'updated')
            ->exists());
    }

    /** Datum vstupu = den schválení (stejně jako schválení přes editaci). */
    public function test_schvaleni_tlacitkem_nastavi_datum_vstupu(): void
    {
        $id = $this->member(MemberType::PENDING_MEMBER, true);
        $this->approve($id);
        $this->assertSame(now()->toDateString(), (string) $this->row($id)->entrance_date);
    }

    /** Hláška neslibuje e-mail, který se nemusel poslat (bez kontaktu / zákazník). */
    public function test_hlaska_neslibuje_email(): void
    {
        $id = $this->member(MemberType::PENDING_MEMBER, true);
        $this->approve($id)->assertSessionHas('success', 'Člen byl schválen.');
    }
}
