<?php

namespace Tests\Characterization\Members;

use App\Domains\Acl\AclService;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace: založení (MemberController::store) a úprava člena (::update)
 * před přesunem do domény Members. Rychlost vs. qos_ceil / smlouva, typ 90 bez
 * rychlosti a schválení editací pokrývají Feature testy (MemberSpeedClassAclTest,
 * TariffAddonApplyOnSignTest, MemberType90NoSpeedTest, MemberApproval*Test);
 * tady zbytek: založení se vším, hlasovací právo, adresa, přepínače, validace.
 */
class MemberStoreUpdateTest extends DatabaseTestCase
{
    private int $adminId;
    private int $townId;
    private int $streetId;
    private int $defaultSpeed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId  = (int) DB::table('users')->where('type', 1)->value('id');
        $this->townId   = (int) DB::table('towns')->value('id');
        $this->streetId = (int) DB::table('streets')->value('id');
        if (!$this->adminId || !$this->townId || !$this->streetId) {
            $this->markTestSkipped('chybí admin / obec / ulice');
        }
        $this->acl();
        $this->defaultSpeed = (int) (DB::table('speed_classes')->where('regular_member_default', 1)->value('id') ?? 1);
    }

    /** ACL: vše povoleno kromě vyjmenovaných [section/value]. */
    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function storeData(array $over = []): array
    {
        return $over + [
            'name' => 'Char', 'surname' => 'Novy', 'type' => 2, 'entrance_date' => '2026-10-01',
            'login' => 'charnew_' . substr(uniqid(), -8), 'password' => \Tests\Support\Fake::secret('heslo'), 'password_confirmation' => \Tests\Support\Fake::secret('heslo'),
            'email' => 'charnew@example.invalid', 'phone' => '+420777000111',
            'town_id' => $this->townId, 'street_id' => $this->streetId, 'street_number' => '12a',
            'organization_identifier' => '12345678', 'vat_organization_identifier' => 'CZ12345678',
            'birthday' => '1990-05-04', 'comment' => 'pozn',
        ];
    }

    private function store(array $data)
    {
        return $this->actingAs(User::find($this->adminId))->from(route('members.create'))->post(route('members.store'), $data);
    }

    // ── založení ─────────────────────────────────────────────────────────────

    public function test_zalozeni_vytvori_clena_uzivatele_ucet_vs_kontakty_adresu(): void
    {
        $data = $this->storeData();
        $res  = $this->store($data);

        $user = DB::table('users')->where('login', $data['login'])->first();
        $this->assertNotNull($user);
        $id = (int) $user->member_id;
        $res->assertRedirect(route('members.show', $id))->assertSessionHas('success', 'Člen byl úspěšně vytvořen.');

        $m = DB::table('members')->where('id', $id)->first();
        $this->assertSame(['Char Novy', 2, '2026-10-01', '9999-12-31', 'pozn', '12345678', 'CZ12345678', 0, 0, $this->defaultSpeed],
            [$m->name, (int) $m->type, $m->entrance_date, $m->leaving_date, $m->comment, $m->organization_identifier,
             $m->vat_organization_identifier, (int) $m->locked, (int) $m->registration, (int) $m->speed_class_id]);

        $ap = DB::table('address_points')->where('id', $m->address_point_id)->first();
        $this->assertSame([$this->townId, $this->streetId, '12a', 1], [(int) $ap->town_id, (int) $ap->street_id, $ap->street_number, (int) $ap->country_id]);

        $this->assertSame(['Char', 'Novy', 1, '1990-05-04', '', ''], [$user->name, $user->surname, (int) $user->type, $user->birthday, $user->comment, $user->settings]);
        $this->assertTrue(Hash::check(\Tests\Support\Fake::secret('heslo'), $user->password));
        $this->assertNotSame('', Crypt::decryptString($user->application_password));

        $acc = DB::table('accounts')->where('member_id', $id)->first();
        $this->assertSame([221100, 0.0, ''], [(int) $acc->account_attribute_id, (float) $acc->balance, $acc->comment]);
        $vs = (string) DB::table('variable_symbols')->where('account_id', $acc->id)->value('variable_symbol');
        $this->assertMatchesRegularExpression('/^' . $id . '\d{2}$/', $vs, 'VS = id člena + 2 náhodné cifry');

        $contacts = DB::table('users_contacts as uc')->join('contacts as c', 'c.id', '=', 'uc.contact_id')
            ->where('uc.user_id', $user->id)->orderBy('c.type')->get(['c.type', 'c.value', 'uc.mail_redirection']);
        $this->assertSame([[20, 'charnew@example.invalid', 0], [21, '+420777000111', 0]],
            $contacts->map(fn ($c) => [(int) $c->type, $c->value, (int) $c->mail_redirection])->all());

        $audit = DB::table('audit_logs')->where('auditable_type', 'members')->where('auditable_id', $id)->where('action', 'created')->first();
        // typ v auditu = hodnota z požadavku (z formuláře řetězec) → volné porovnání
        $this->assertEquals(['name' => 'Char Novy', 'type' => 2, 'entrance_date' => '2026-10-01', 'login' => $data['login']], json_decode($audit->new_values, true));
    }

    public function test_zalozeni_radny_bez_rychlosti_a_bez_prijmeni(): void
    {
        $data = $this->storeData(['type' => 90, 'surname' => null]);
        $this->store($data)->assertSessionHasNoErrors();
        $user = DB::table('users')->where('login', $data['login'])->first();
        $m = DB::table('members')->where('id', $user->member_id)->first();
        $this->assertSame(['Char', null], [$m->name, $m->speed_class_id]);
        $this->assertSame('', $user->surname);
    }

    public function test_zalozeni_validace(): void
    {
        $this->store($this->storeData(['login' => DB::table('users')->value('login')]))->assertSessionHasErrors('login');
        $this->store($this->storeData(['street_number' => 'abc']))->assertSessionHasErrors('street_number');
        $this->store($this->storeData(['organization_identifier' => 'a@b.cz']))
            ->assertSessionHasErrors(['organization_identifier' => 'IČO nesmí obsahovat email ani mezery — zadejte pouze číslo IČO.']);
        $this->store($this->storeData(['password_confirmation' => 'jine']))->assertSessionHasErrors('password');
        $this->store($this->storeData(['street_number' => 'ev. č. 15']))->assertSessionHasNoErrors();
    }

    public function test_zalozeni_bez_prava_403(): void
    {
        $this->acl(['Members_Controller/members']);
        $this->store($this->storeData())->assertForbidden();
    }

    // ── úprava ───────────────────────────────────────────────────────────────

    private function member(int $type = 90, array $m = []): int
    {
        $ap = (int) DB::table('address_points')->insertGetId(['town_id' => $this->townId, 'street_id' => $this->streetId, 'street_number' => '1', 'country_id' => 1]);
        return (int) DB::table('members')->insertGetId($m + [
            'name' => 'Char Upd', 'type' => $type, 'registration' => 1, 'locked' => 0, 'address_point_id' => $ap,
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31', 'can_vote' => 0,
        ]);
    }

    private function update(int $id, array $data)
    {
        return $this->actingAs(User::find($this->adminId))->put(route('members.update', $id), $data + ['name' => 'Char Upd', 'type' => 90]);
    }

    public function test_uprava_zakladni_pole_a_prepinace(): void
    {
        $id = $this->member(90, ['notification_by_email' => 1, 'comment' => 'x', 'organization_identifier' => '1']);
        $this->update($id, [
            'name' => 'Char Zmena', 'entrance_date' => '2020-02-02', 'leaving_date' => '2027-01-31', 'comment' => 'nove',
            'locked' => '1', 'registration' => '0', 'notification_by_redirection' => '1', 'notification_by_sms' => '1',
        ])->assertRedirect(route('members.show', $id))->assertSessionHas('success', 'Člen byl úspěšně upraven.');

        $m = DB::table('members')->where('id', $id)->first();
        $this->assertSame(['Char Zmena', '2020-02-02', '2027-01-31', 'nove', null, 1, 0, 1, 0, 1],
            [$m->name, $m->entrance_date, $m->leaving_date, $m->comment, $m->organization_identifier, (int) $m->locked,
             (int) $m->registration, (int) $m->notification_by_redirection, (int) $m->notification_by_email, (int) $m->notification_by_sms]);
    }

    public function test_uprava_prazdny_odchod_neurceno(): void
    {
        $id = $this->member(90, ['leaving_date' => '2027-01-31']);
        $this->update($id, ['leaving_date' => ''])->assertSessionHasNoErrors();
        $this->assertSame('9999-12-31', DB::table('members')->where('id', $id)->value('leaving_date'));
    }

    public function test_uprava_hlasovaci_pravo_jen_radny_a_log(): void
    {
        $id = $this->member(90);
        $this->update($id, ['can_vote' => '1']);
        $this->assertSame(1, (int) DB::table('members')->where('id', $id)->value('can_vote'));
        $log = DB::table('member_voting_logs')->where('member_id', $id)->first();
        $this->assertSame(['granted', 'Ruční změna v editaci člena', $this->adminId], [$log->action, $log->reason, (int) $log->user_id]);

        $this->update($id, ['can_vote' => '1']);
        $this->assertSame(1, DB::table('member_voting_logs')->where('member_id', $id)->count(), 'beze změny žádný log');

        // zákazník can_vote nesmí mít
        $this->update($id, ['type' => 2, 'can_vote' => '1']);
        $this->assertSame(0, (int) DB::table('members')->where('id', $id)->value('can_vote'));
        $this->assertSame('degraded', DB::table('member_voting_logs')->where('member_id', $id)->orderByDesc('id')->value('action'));
    }

    public function test_uprava_bez_prava_schuzi_hlasovani_nemeni(): void
    {
        $this->acl(['Meetings_Controller/meeting']);
        $id = $this->member(90, ['can_vote' => 1]);
        $this->update($id, []);
        $this->assertSame(1, (int) DB::table('members')->where('id', $id)->value('can_vote'));
        $this->assertFalse(DB::table('member_voting_logs')->where('member_id', $id)->exists());
    }

    public function test_uprava_adresy_na_miste(): void
    {
        $id = $this->member(90);
        $ap = (int) DB::table('members')->where('id', $id)->value('address_point_id');
        $this->update($id, ['town_id' => $this->townId, 'street_id' => $this->streetId, 'street_number' => '99']);
        $this->assertSame($ap, (int) DB::table('members')->where('id', $id)->value('address_point_id'), 'stávající bod se upraví na místě');
        $this->assertSame('99', DB::table('address_points')->where('id', $ap)->value('street_number'));

        // bez obce se adresa nemění
        $this->update($id, ['street_number' => '55']);
        $this->assertSame('99', DB::table('address_points')->where('id', $ap)->value('street_number'));
    }

    public function test_uprava_validace_a_prava(): void
    {
        $id = $this->member(90);
        $this->update($id, ['leaving_date' => '31.12.2027'])->assertSessionHasErrors('leaving_date');
        $this->update($id, ['vat_organization_identifier' => 'CZ 1'])
            ->assertSessionHasErrors(['vat_organization_identifier' => 'DIČ nesmí obsahovat email ani mezery — zadejte pouze DIČ (např. CZ12345678).']);
        $this->actingAs(User::find($this->adminId))->put(route('members.update', 999999999), ['name' => 'x', 'type' => 90])->assertNotFound();
        $this->acl(['Members_Controller/members']);
        $this->update($id, [])->assertForbidden();
    }
}
