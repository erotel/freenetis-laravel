<?php

namespace Tests\Characterization\Members;

use App\Domains\Acl\AclService;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace čtecí strany MemberController před přesunem do domény
 * Members: seznam (hledání, filtry, řazení, stránkování), karta člena (data
 * a vlastní profil), čekatelé, k ukončení, registrační PDF (inline i e-mailem)
 * a vlastní nastavení oznámení.
 */
class MemberReadTest extends DatabaseTestCase
{
    private int $adminId;
    private int $townId;
    private string $tag;
    /** @var string[] přílohy před testem — e-mail exportu je zapisuje na disk */
    private array $attachments = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->townId  = (int) DB::table('towns')->value('id');
        if (!$this->adminId || !$this->townId) {
            $this->markTestSkipped('chybí admin / obec');
        }
        $this->acl();
        $this->tag = 'Zq' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $this->attachments = glob(storage_path('app/email-attachments/*.pdf')) ?: [];
    }

    protected function tearDown(): void
    {
        foreach (array_diff(glob(storage_path('app/email-attachments/*.pdf')) ?: [], $this->attachments) as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    /** @return array{id:int, account:int, user:int} */
    private function member(string $name, int $type = 2, array $m = [], float $balance = 0, ?string $street = null): array
    {
        $ap = (int) DB::table('address_points')->insertGetId(['town_id' => $this->townId, 'street_id' => $street ? (int) DB::table('streets')->insertGetId(['street' => $street]) : null,
            'street_number' => '42', 'country_id' => 1]);
        $id = (int) DB::table('members')->insertGetId($m + [
            'name' => $name, 'type' => $type, 'registration' => 1, 'locked' => 0, 'address_point_id' => $ap,
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31',
        ]);
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => $balance, 'name' => 'char']);
        DB::table('variable_symbols')->insert(['account_id' => $acc, 'variable_symbol' => '77' . $id]);
        $uid = (int) DB::table('users')->insertGetId(['member_id' => $id, 'login' => 'charread_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'xxxxxxxx', 'settings' => '', 'name' => 'Char', 'surname' => 'Read', 'comment' => '']);
        return ['id' => $id, 'account' => $acc, 'user' => $uid];
    }

    private function as(?int $userId = null)
    {
        return $this->actingAs(User::find($userId ?? $this->adminId));
    }

    /** @return int[] id členů na stránce seznamu */
    private function listed(array $query): array
    {
        $res = $this->as()->get(route('members.index', $query))->assertOk();
        return collect($res->viewData('members')->items())->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    // ── seznam ───────────────────────────────────────────────────────────────

    public function test_seznam_hledani_dle_jmena_vs_adresy_tokenu_a_id(): void
    {
        $a = $this->member("Alfa {$this->tag}", 2, [], 150, "Ulice{$this->tag}");
        $b = $this->member("Beta {$this->tag}", 90);

        $this->assertEqualsCanonicalizing([$a['id'], $b['id']], $this->listed(['search' => $this->tag]));
        $this->assertSame([$a['id']], $this->listed(['search' => '77' . $a['id']]), 'VS');
        $this->assertSame([$a['id']], $this->listed(['search' => "Ulice{$this->tag}"]), 'ulice');
        $this->assertSame([$a['id']], $this->listed(['search' => "Alfa Ulice{$this->tag}"]), 'víc slov: jméno + ulice');
        $this->assertContains($b['id'], $this->listed(['search' => (string) $b['id']]), 'id');

        $res = $this->as()->get(route('members.index', ['search' => "Alfa {$this->tag}"]));
        $row = collect($res->viewData('members')->items())->first();
        $this->assertEqualsWithDelta(150.0, (float) $row->credit_balance, 0.001);
        $res->assertViewHas('memberTypes', fn ($t) => array_keys($t) === [2, 90, 16, 15, 17, 18]);
    }

    public function test_seznam_filtr_typu_zamku_razeni_a_stranka(): void
    {
        $a = $this->member("Alfa {$this->tag}", 2);
        $b = $this->member("Beta {$this->tag}", 90, ['locked' => 1]);

        $this->assertSame([$b['id']], $this->listed(['search' => $this->tag, 'types' => '90,15']));
        $this->assertSame([$a['id']], $this->listed(['search' => $this->tag, 'locked' => '0']));
        $this->assertSame([$b['id'], $a['id']], $this->listed(['search' => $this->tag, 'sort' => 'name', 'dir' => 'desc']));

        $res = $this->as()->get(route('members.index', ['sort' => 'comment; drop', 'dir' => 'x', 'record_per_page' => 7, 'types' => 'abc']))->assertOk();
        $res->assertViewHas('sort', 'id')->assertViewHas('dir', 'asc')->assertViewHas('perPage', 50)->assertViewHas('currentTypes', 'all');
        $this->as()->get(route('members.index', ['record_per_page' => 100]))->assertViewHas('perPage', 100);
    }

    public function test_seznam_bez_prava_403(): void
    {
        $this->acl(['view_all/Members_Controller/members']);
        $this->as()->get(route('members.index'))->assertForbidden();
    }

    // ── karta člena ──────────────────────────────────────────────────────────

    public function test_karta_data(): void
    {
        $fee = (int) DB::table('fees')->insertGetId(['readonly' => 0, 'fee' => 250, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => 35, 'name' => 'char', 'special_type_id' => null]);
        Setting::set('default_fee_member_type_2', $fee);
        $x = $this->member("Karta {$this->tag}", 2, [], 500);
        DB::table('audit_logs')->insert(['occurred_at' => now(), 'user_id' => $this->adminId, 'action' => 'updated', 'auditable_type' => 'users', 'auditable_id' => $x['user'], 'new_values' => '{}']);

        $res = $this->as()->get(route('members.show', $x['id']))->assertOk();
        $res->assertViewHas('variableSymbols', fn ($vs) => $vs->all() === ['77' . $x['id']])
            ->assertViewHas('creditAccount', fn ($a) => (int) $a->id === $x['account'])
            ->assertViewHas('mainUser', fn ($u) => (int) $u->id === $x['user'])
            ->assertViewHas('monthlyFee', 250.0)->assertViewHas('monthlyFeeSource', 'základní')
            ->assertViewHas('activeMemberFee', fn ($mf) => (float) $mf->fee->fee === 250.0)
            ->assertViewHas('hasActiveInterrupt', false)
            ->assertViewHas('auditHistory', fn ($h) => $h->contains(fn ($r) => $r->auditable_type === 'users' && (int) $r->auditable_id === $x['user']))
            ->assertViewHas('canViewAudit', true)
            ->assertViewHas('additionalServicesTotal', 0)->assertViewHas('subnetFeesTotal', 0)
            ->assertViewHas('accountComments', '')
            ->assertViewHas('expirationDate');
    }

    public function test_karta_vlastni_profil_bez_prava_a_cizi_403_404(): void
    {
        $own   = $this->member("Vlastni {$this->tag}");
        $other = $this->member("Cizi {$this->tag}");
        $this->acl(['view_all/Members_Controller/members', 'edit_all/Members_Controller/members']);

        $this->as($own['user'])->get(route('members.show', $own['id']))->assertOk()
            ->assertViewHas('canViewAudit', false)->assertViewHas('auditHistory', fn ($h) => $h->isEmpty());
        $this->as($own['user'])->get(route('members.show', $other['id']))->assertForbidden();
        $this->as()->get(route('members.show', 999999999))->assertForbidden();
        $this->acl();
        $this->as()->get(route('members.show', 999999999))->assertNotFound();
    }

    // ── čekatelé / k ukončení ────────────────────────────────────────────────

    public function test_cekatele_a_k_ukonceni(): void
    {
        $p17 = $this->member("Cekatel {$this->tag}", 17);
        $p18 = $this->member("Cekatel2 {$this->tag}", 18);
        $left = $this->member("Odesel {$this->tag}", 18, ['leaving_date' => '2026-01-01']);
        $pending = $this->member("Konec {$this->tag}", 2, ['pending_termination' => 1, 'payment_blocked_since' => '2026-09-01'], -300);
        $pendingFormer = $this->member("KonecB {$this->tag}", 16, ['pending_termination' => 1]);

        $ids = $this->as()->get(route('members.applicants'))->assertOk()->viewData('pending')->pluck('id')->map(fn ($i) => (int) $i);
        $this->assertTrue($ids->contains($p17['id']) && $ids->contains($p18['id']));
        $this->assertFalse($ids->contains($left['id']));

        $rows = $this->as()->get(route('members.pending-termination'))->assertOk()->assertViewHas('canEdit', true)->viewData('rows');
        $row = $rows->firstWhere('id', $pending['id']);
        $this->assertSame(['2026-09-01', -300.0, '77' . $pending['id']], [$row->payment_blocked_since, (float) $row->balance, $row->variable_symbols]);
        $this->assertNull($rows->firstWhere('id', $pendingFormer['id']), 'jen typ 2/90');

        $this->acl(['view_all/Members_Controller/members']);
        $this->as()->get(route('members.applicants'))->assertForbidden();
        $this->as()->get(route('members.pending-termination'))->assertForbidden();
    }

    // ── registrační PDF ──────────────────────────────────────────────────────

    public function test_registracni_pdf_inline_typy(): void
    {
        $x = $this->member("Pdf {$this->tag}");
        foreach (['registration' => 'prihlaska', 'end' => 'ukonceni-clenstvi', 'contract_end' => 'vypoved-smlouvy'] as $type => $prefix) {
            $res = $this->as()->get(route('members.registration-export', [$x['id'], $type]))->assertOk();
            $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
            $this->assertSame('inline; filename="' . $prefix . '-' . $x['id'] . '.pdf"', $res->headers->get('Content-Disposition'));
            $this->assertStringStartsWith('%PDF', $res->getContent());
        }
        $this->as()->get(route('members.registration-export', [$x['id'], 'jiny']))->assertNotFound();
    }

    public function test_registracni_pdf_emailem(): void
    {
        $x = $this->member("Mail {$this->tag}");
        $this->as()->from(route('members.show', $x['id']))->post(route('members.registration-export-email', [$x['id'], 'end']))
            ->assertRedirect(route('members.show', $x['id']))->assertSessionHas('error', 'Člen nemá v kontaktech žádný e-mail.');

        foreach (['druhy@example.invalid', 'prvni@example.invalid'] as $i => $mail) {
            $cid = (int) DB::table('contacts')->insertGetId(['type' => 20, 'value' => $mail]);
            DB::table('users_contacts')->insert(['user_id' => $x['user'], 'contact_id' => $cid]);
        }
        $first = DB::table('users_contacts as uc')->join('contacts as c', 'c.id', '=', 'uc.contact_id')->where('uc.user_id', $x['user'])->orderBy('c.id')->value('c.value');

        $this->as()->from(route('members.show', $x['id']))->post(route('members.registration-export-email', [$x['id'], 'end']))
            ->assertSessionHas('success', 'PDF „Ukončení členství" zařazeno do fronty k odeslání na ' . $first . '.');
        $mail = DB::table('email_queues')->where('to', $first)->orderByDesc('id')->first();
        $this->assertSame(['Ukončení členství', 0], [$mail->subject, (int) $mail->state]);
        $this->assertStringContainsString('<strong>Ukončení členství</strong>', $mail->body);
        $att = DB::table('email_queue_attachments')->where('email_queue_id', $mail->id)->first();
        $this->assertSame(['ukonceni-clenstvi-' . $x['id'] . '.pdf', 'application/pdf'], [$att->name, $att->mime]);
        $this->assertStringStartsWith(storage_path('app/email-attachments/ukonceni-clenstvi-' . $x['id'] . '-'), $att->path);
        $this->assertFileExists($att->path);

        $this->as()->post(route('members.registration-export-email', [999999999, 'end']))->assertNotFound();
        $this->acl(['view_all/Members_Controller/registration_export']);
        $this->as()->post(route('members.registration-export-email', [$x['id'], 'end']))->assertForbidden();
    }

    // ── vlastní oznámení ─────────────────────────────────────────────────────

    public function test_vlastni_nastaveni_oznameni(): void
    {
        $x = $this->member("Ozn {$this->tag}", 2, ['notification_by_email' => 1]);
        $this->as($x['user'])->get(route('me.notifications'))->assertOk()->assertViewHas('member', fn ($m) => (int) $m->id === $x['id']);
        $this->as($x['user'])->post(route('me.notifications.update'), ['notification_by_sms' => '1'])
            ->assertRedirect(route('me.notifications'))->assertSessionHas('success', 'Nastavení oznámení bylo uloženo.');
        $m = DB::table('members')->where('id', $x['id'])->first();
        $this->assertSame([0, 0, 1], [(int) $m->notification_by_redirection, (int) $m->notification_by_email, (int) $m->notification_by_sms]);

    }
}
