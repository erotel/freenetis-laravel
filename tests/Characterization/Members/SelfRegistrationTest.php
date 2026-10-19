<?php

namespace Tests\Characterization\Members;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace veřejné samoregistrace (RegistrationController) před
 * přesunem do domény: založí adresu, člena (17/18 čekající bez data vstupu,
 * 3 organizace s datem vstupu), hlavního uživatele, kreditní účet + VS
 * (id člena + 2 číslice), e-mail a telefon; validace (18+, regexy, login),
 * audit, souhrn smlouvy e-mailem s PDF (ne čekajícímu členovi 17).
 */
class SelfRegistrationTest extends DatabaseTestCase
{
    private int $townId;
    private int $streetId;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('self_registration', 1);
        Setting::set('registration_summary_enabled', 0);
        Setting::set('security_password_level', 4);
        Setting::set('security_password_length', 8);
        $this->townId   = (int) DB::table('towns')->value('id');
        $this->streetId = (int) DB::table('streets')->value('id');
        $this->tag = 'sr' . substr(md5(uniqid('', true)), 0, 8);
    }

    private function data(array $over = []): array
    {
        return $over + [
            'registration_type' => 18, 'name' => 'Jana', 'surname' => 'Nováková', 'birthday' => '1990-05-04',
            'login' => $this->tag, 'password' => 'Silne-Heslo-2026', 'password_confirmation' => 'Silne-Heslo-2026',
            'email' => "{$this->tag}@example.invalid", 'phone' => '+420777000111',
            'town_id' => $this->townId, 'street_id' => $this->streetId, 'street_number' => 'ev. č. 12a',
            'organization_identifier' => '', 'vat_organization_identifier' => '', 'comment' => 'pozn',
        ];
    }

    private function user(): ?object
    {
        return DB::table('users')->where('login', $this->tag)->first();
    }

    public function test_formular_a_vypnuto(): void
    {
        $this->get(route('registration.create'))->assertOk()->assertViewIs('registration.create')
            ->assertViewHas('towns', fn ($t) => $t->count() === DB::table('towns')->count());
        Setting::set('self_registration', 0);
        $this->get(route('registration.create'))->assertNotFound();
        $this->post(route('registration.store'), $this->data())->assertNotFound();
    }

    public function test_cekajici_zakaznik(): void
    {
        $this->post(route('registration.store'), $this->data())->assertRedirect(route('registration.success'));
        $this->get(route('registration.success'))->assertOk()->assertViewIs('registration.success');

        $u = $this->user();
        $this->assertSame(['Jana', 'Nováková', 1, '1990-05-04', '', ''], [$u->name, $u->surname, (int) $u->type, $u->birthday, $u->comment, $u->settings]);
        $this->assertTrue(Hash::check('Silne-Heslo-2026', $u->password));
        $this->assertNotSame('', (string) $u->application_password);

        $m = DB::table('members')->where('id', $u->member_id)->first();
        $this->assertSame(['Jana Nováková', 18, null, '9999-12-31', 'pozn', '', '', 0, 0, null],
            [$m->name, (int) $m->type, $m->entrance_date, $m->leaving_date, $m->comment, $m->organization_identifier, $m->vat_organization_identifier,
             (int) $m->locked, (int) $m->registration, $m->speed_class_id]);
        $ap = DB::table('address_points')->where('id', $m->address_point_id)->first();
        $this->assertSame([$this->townId, $this->streetId, 1, 'ev. č. 12a'], [(int) $ap->town_id, (int) $ap->street_id, (int) $ap->country_id, $ap->street_number]);

        $acc = DB::table('accounts')->where('member_id', $m->id)->first();
        $this->assertSame([221100, 0.0], [(int) $acc->account_attribute_id, (float) $acc->balance]);
        $vs = DB::table('variable_symbols')->where('account_id', $acc->id)->value('variable_symbol');
        $this->assertMatchesRegularExpression('/^' . $m->id . '\d{2}$/', $vs);

        $contacts = DB::table('contacts as c')->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')->where('uc.user_id', $u->id)
            ->orderBy('c.type')->get(['c.type', 'c.value', 'uc.mail_redirection'])->map(fn ($r) => [(int) $r->type, $r->value, (int) $r->mail_redirection])->all();
        $this->assertSame([[20, "{$this->tag}@example.invalid", 0], [21, '+420777000111', 0]], $contacts);

        $a = DB::table('audit_logs')->where('auditable_type', 'members')->where('auditable_id', $m->id)->orderByDesc('id')->first();
        $this->assertSame(['created', null, ['type' => 18, 'login' => $this->tag, 'via' => 'veřejná registrace']], [$a->action, $a->user_id, json_decode($a->new_values, true)]);
    }

    public function test_organizace_a_cekajici_clen(): void
    {
        $longName = trim(str_repeat('Firma s.r.o. ', 5));   // 64 znaků
        $this->post(route('registration.store'), $this->data(['registration_type' => 3, 'name' => $longName, 'surname' => '',
            'birthday' => now()->subYear()->toDateString(), 'organization_identifier' => '12345678', 'vat_organization_identifier' => 'CZ12345678']))
            ->assertRedirect(route('registration.success'));
        $u = $this->user();
        $m = DB::table('members')->where('id', $u->member_id)->first();
        $this->assertSame([3, now()->toDateString(), $longName, '12345678', 'CZ12345678'], [(int) $m->type, $m->entrance_date, $m->name, $m->organization_identifier, $m->vat_organization_identifier]);
        $this->assertSame([mb_substr($longName, 0, 30), mb_substr($longName, 30, 60)], [$u->name, $u->surname], 'jméno org. rozdělené do name/surname');

        $this->tag .= 'b';
        $this->post(route('registration.store'), $this->data(['registration_type' => 17]))->assertRedirect(route('registration.success'));
        $m = DB::table('members')->where('id', $this->user()->member_id)->first();
        $this->assertSame([17, null], [(int) $m->type, $m->entrance_date]);
    }

    public function test_validace(): void
    {
        $this->post(route('registration.store'), $this->data(['birthday' => now()->subYears(17)->toDateString()]))
            ->assertSessionHasErrors(['birthday' => 'Registrace je možná až od 18 let.']);
        $this->post(route('registration.store'), $this->data(['organization_identifier' => 'a@b.cz', 'vat_organization_identifier' => 'CZ 1']))
            ->assertSessionHasErrors(['organization_identifier' => 'IČO nesmí obsahovat email ani mezery — zadejte pouze číslo IČO.',
                'vat_organization_identifier' => 'DIČ nesmí obsahovat email ani mezery — zadejte pouze DIČ (např. CZ12345678).']);
        $this->post(route('registration.store'), $this->data(['name' => 'Jan<script>', 'street_number' => 'abc', 'registration_type' => 2, 'login' => 'abc']))
            ->assertSessionHasErrors(['name', 'street_number', 'registration_type', 'login']);
        $this->assertNull($this->user());
    }

    public function test_souhrn_smlouvy_e_mailem(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'shrnuti') . '.pdf';
        file_put_contents($path, '%PDF');
        Setting::set('registration_summary_enabled', 1);
        Setting::set('registration_summary_pdf', $path);   // absolutní cesta; relativní = vůči storage/app/private
        Setting::set('email_default_email', 'noreply@example.test');

        $this->post(route('registration.store'), $this->data());
        $mail = DB::table('email_queues')->where('to', "{$this->tag}@example.invalid")->first();
        $this->assertSame(['noreply@example.test', 'Shrnutí smlouvy - PVfree.net', 0], [$mail->from, $mail->subject, (int) $mail->state]);
        $att = DB::table('email_queue_attachments')->where('email_queue_id', $mail->id)->first();
        $this->assertSame([$path, 'smlouva_shrnuti.pdf', 'application/pdf'], [$att->path, $att->name, $att->mime]);

        $this->tag .= 'c';
        $this->post(route('registration.store'), $this->data(['registration_type' => 17]));
        $this->assertNull(DB::table('email_queues')->where('to', "{$this->tag}@example.invalid")->first(), 'čekající člen ne');

        $this->tag .= 'd';
        Setting::set('registration_summary_pdf', 'chybi.pdf');
        $this->post(route('registration.store'), $this->data());
        $this->assertNull(DB::table('email_queues')->where('to', "{$this->tag}@example.invalid")->first(), 'bez PDF nic');
        @unlink($path);
    }
}
