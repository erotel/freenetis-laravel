<?php

namespace Tests\Characterization\Billing;

use App\Models\Invoice;
use App\Models\Setting;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace dokladů k platbě (InvoiceService) před přesunem do domény
 * Billing: faktura za služby (číslo YYYY00001…, položka bez DPH 21 % +
 * zaokrouhlení, PDF, e-mail s přílohou) a potvrzení přijaté platby
 * (šablona 9 / záložní text). Šablona „Payment confirmation - customer"
 * v DB (test i .54) chybí → e-mail faktury vždy záložním textem.
 */
class InvoiceTest extends DatabaseTestCase
{
    private int $memberId;
    private int $bankAccountId;
    private array $pdfs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $this->memberId = (int) DB::table('members')->insertGetId(['name' => 'Char Faktura', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $apId, 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31', 'organization_identifier' => '11223344']);
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $this->memberId, 'account_attribute_id' => 221100, 'balance' => 0, 'name' => 'c']);
        DB::table('variable_symbols')->insert(['account_id' => $acc, 'variable_symbol' => '8' . $this->memberId]);
        $uid = (int) DB::table('users')->insertGetId(['member_id' => $this->memberId, 'login' => 'charinv_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'F', 'comment' => '']);
        foreach (['charinv@example.invalid', 'charinv@example.invalid', 'charinv2@example.invalid'] as $mail) {
            $cid = (int) DB::table('contacts')->insertGetId(['type' => 20, 'value' => $mail]);
            DB::table('users_contacts')->insert(['user_id' => $uid, 'contact_id' => $cid]);
        }
        $this->bankAccountId = (int) DB::table('bank_accounts')->insertGetId(['name' => 'Char Služby', 'member_id' => 1, 'account_nr' => '123', 'bank_nr' => '2010', 'payment_purpose' => 1]);
        Setting::set('email_default_email', 'char@example.invalid');
        Setting::set('email_subject_prefix', 'CHAR');
    }

    protected function tearDown(): void
    {
        foreach ($this->pdfs as $f) @unlink($f);
        parent::tearDown();
    }

    private function invoice(float $amount, array $btIds = [77]): Invoice
    {
        $inv = app(InvoiceService::class)->generateServicesInvoice($this->memberId, $amount, $this->bankAccountId, $btIds);
        $this->assertNotNull($inv);
        if ($inv->pdf_filename) $this->pdfs[] = $inv->pdf_filename;
        return $inv->fresh(['items']);
    }

    public function test_faktura_cislo_hlavicka_polozky_zaokrouhleni(): void
    {
        $year = now()->year;
        $max  = (int) Invoice::whereBetween('invoice_nr', [$year * 100000, $year * 100000 + 99999])->max('invoice_nr');

        $inv = $this->invoice(100);
        $this->assertSame($max ? $max + 1 : $year * 100000 + 1, (int) $inv->invoice_nr);
        $this->assertSame([Invoice::TYPE_ISSUED, '123/2010', (float) ('8' . $this->memberId), null, now()->toDateString(), now()->addDays(14)->toDateString(), 1, 'Char Faktura', '11223344'],
            [(int) $inv->invoice_type, $inv->account_nr, (float) $inv->var_sym, $inv->con_sym, substr((string) $inv->date_inv, 0, 10), substr((string) $inv->date_due, 0, 10),
             (int) $inv->vat, $inv->partner_name, $inv->organization_identifier]);
        $this->assertStringStartsWith('AUTO PVFREE SERVICES ', $inv->note);

        $items = $inv->items->map(fn ($i) => [$i->name, $i->code, (float) $i->price, (float) $i->vat, (int) $i->service])->all();
        $this->assertSame([
            ['Platba za připojení k síti Internet', 'BANK-' . now()->format('Y-m'), 82.64, 0.21, 1],
            ['Zaokrouhlení', 'ROUND', 0.01, 0.0, 0],
        ], $items, '100 Kč: 82,64 + DPH 17,35 = 99,99 → zaokrouhlení 0,01');

        $this->assertSame($max ? $max + 2 : $year * 100000 + 2, (int) $this->invoice(121)->invoice_nr, 'další číslo');
        $this->assertCount(1, $this->invoice(121)->items, '121 Kč: 100 + 21 bez zaokrouhlení');
    }

    public function test_faktura_pdf_a_email(): void
    {
        $inv = $this->invoice(250, [11, 12]);
        $this->assertSame(InvoiceService::pdfBasePath() . '/' . now()->year . '/' . (int) $inv->invoice_nr . '.pdf', $inv->pdf_filename);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($inv->pdf_filename));

        $mails = DB::table('email_queues')->where('subject', 'Faktura ' . (int) $inv->invoice_nr)->orderBy('id')->get();
        $this->assertEqualsCanonicalizing(['charinv2@example.invalid', 'charinv@example.invalid'], $mails->pluck('to')->all(), 'e-maily člena bez duplicit');
        $this->assertSame('char@example.invalid', $mails[0]->from);
        $this->assertStringContainsString('fakturu č. <strong>' . (int) $inv->invoice_nr . '</strong>', $mails[0]->body);
        $this->assertStringContainsString('<p>ID platby: 11, 12</p>', $mails[0]->body);
        $this->assertStringContainsString('<strong>250,00 Kč</strong>', $mails[0]->body);
        $att = DB::table('email_queue_attachments')->where('email_queue_id', $mails[0]->id)->first();
        $this->assertSame(['faktura_' . (int) $inv->invoice_nr . '.pdf', $inv->pdf_filename], [$att->name, $att->path]);
    }

    public function test_faktura_neexistujici_clen(): void
    {
        $this->assertNull(app(InvoiceService::class)->generateServicesInvoice(999999999, 100, $this->bankAccountId));
    }

    public function test_potvrzeni_platby_sablona_a_zalozni_text(): void
    {
        DB::table('messages')->where('id', 9)->update(['email_text' => 'PID={payment_id};A={payment_amount};B={payment_amount_novat};V={payment_vat};VS={variable_symbol};ICO={organization_identifier}']);
        app(InvoiceService::class)->queuePaymentConfirmation($this->memberId, 121, 'Char Příspěvky', [5, 6]);
        $mail = DB::table('email_queues')->where('to', 'charinv@example.invalid')->orderByDesc('id')->first();
        $this->assertSame('CHAR :: ' . DB::table('messages')->where('id', 9)->value('name'), $mail->subject);
        $this->assertSame('PID=5;A=121,00;B=100,00;V=21,00;VS=8' . $this->memberId . ';ICO=11223344', $mail->body);
        $this->assertSame(2, DB::table('email_queues')->where('body', $mail->body)->count(), 'na oba e-maily');

        DB::table('messages')->where('id', 9)->update(['email_text' => '']);
        app(InvoiceService::class)->queuePaymentConfirmation($this->memberId, 50, 'Char <Příspěvky>', []);
        $mail = DB::table('email_queues')->where('to', 'charinv@example.invalid')->orderByDesc('id')->first();
        $this->assertStringContainsString('<strong>50,00 Kč</strong> na účet <strong>Char &lt;Příspěvky&gt;</strong>', $mail->body);
    }
}
