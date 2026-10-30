<?php

namespace Tests\Characterization\Contracts;

use App\Domains\Contracts\Otp\OtpService;
use App\Domains\Contracts\Pdf\PdfService;
use App\Models\ContractAddon;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace veřejného podepisování (PublicSignController, /sign/*) před
 * přesunem do domény Contracts: info, náhled, OTP, finalizace smlouvy
 * (stav, otisk, aktivace člena, e-mail s přílohami, jednorázové stažení),
 * starý dodatek, výpověď, stažení přes token a tři typy dodatků (info,
 * náhled, ověření OTP → podpis + e-mail + stažení). PDF a SMS podvržené.
 *
 * Náhled už podepsaného dodatku vrací uložené PDF (do 2026-10-16 HTTP 500).
 */
class PublicSignTest extends DatabaseTestCase
{
    use \Tests\Support\IssuesContractAddonLinks;

    private int $memberId;
    private int $contractId;
    private string $contractNo;
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $this->memberId = (int) DB::table('members')->insertGetId([
            'name' => 'Char Public', 'type' => 18, 'registration' => 0, 'locked' => 0, 'address_point_id' => $apId,
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31',
        ]);
        $this->contractNo = 'SML-CHAR-PS-' . uniqid();
        $this->contractId = (int) DB::connection('contracts')->table('contracts')->insertGetId([
            'member_id' => $this->memberId, 'contract_no' => $this->contractNo, 'status' => 'otp_verified', 'phone' => '+420777123456', 'created_at' => now(),
        ]);
        DB::connection('contracts')->table('contract_parties')->insert([
            'contract_id' => $this->contractId, 'full_name' => 'Char Public', 'variable_symbol' => '4242', 'email' => 'charpublic@example.invalid',
            'termination' => 'ano', 'created_at' => now(),
        ]);
        Setting::set('email_subject_prefix', 'CHAR');
        Setting::set('contract_email_attachments_enabled', 1);
        Setting::set('contract_email_pricelist_pdf', '');
        Setting::set('contract_email_vop_pdf', '');
        Message::whereIn('type', [Message::CONTRACT_SIGNED, Message::CONTRACT_ADDON_SIGNED])->update(['email_text' => '']);

        $pdf = $this->createMock(PdfService::class);
        foreach (['renderContractPdf', 'renderAddonPdf', 'renderTariffAddonPdf', 'renderServiceAddonPdf', 'renderConnectionPointAddonPdf'] as $m) {
            $pdf->method($m)->willReturnCallback(fn ($x, $preview = false) => $preview === true ? '%PDF-preview-' . $m : $this->file($m));
        }
        $this->app->instance(PdfService::class, $pdf);

        $otp = $this->createMock(OtpService::class);
        $otp->method('sendOtp')->willReturn(['ok' => true, 'status' => 200]);
        $otp->method('verifyOtp')->willReturnCallback(fn ($c, $code) => $code === '1234' ? ['ok' => true] : ['ok' => false, 'error' => 'Špatný kód', 'status' => 422]);
        $otp->method('sendAddonOtp')->willReturn(['ok' => true]);
        $otp->method('verifyAddonOtp')->willReturnCallback(fn ($c, $code) => $code === '1234' ? ['ok' => true] : ['ok' => false, 'error' => 'Špatný kód', 'status' => 422]);
        $this->app->instance(OtpService::class, $otp);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) @unlink($f);
        parent::tearDown();
    }

    private function file(string $tag): string
    {
        $f = tempnam(sys_get_temp_dir(), 'charps');
        file_put_contents($f, '%PDF-' . $tag);
        return $this->files[] = $f;
    }

    private function token(?int $addonId = null): string
    {
        $this->actingAs(\App\Models\User::find((int) DB::table('users')->where('type', 1)->value('id')));
        $url = $addonId ? $this->issueAddonLink($addonId)['url'] : app(\App\Domains\Contracts\Actions\IssueSignLinkAction::class)->handle($this->contractId, \App\Domains\Contracts\Support\SignLinkKind::Contract)['url'];
        auth()->logout();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return $q['t'];
    }

    private function events(string $event)
    {
        return DB::connection('contracts')->table('contract_events')->where('contract_id', $this->contractId)->where('event', $event)->get()
            ->map(fn ($e) => json_decode($e->meta_json, true));
    }

    private function dlRow(string $url): object
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return DB::connection('contracts')->table('download_tokens')->where('token', $q['t'])->first();
    }

    // ── smlouva ──────────────────────────────────────────────────────────────

    public function test_info_nahled_a_neplatny_token(): void
    {
        $t = $this->token();
        $this->postJson(route('sign.info'), ['t' => $t])->assertOk()->assertExactJson([
            'id' => $this->contractId, 'member_id' => $this->memberId, 'contract_no' => $this->contractNo, 'status' => 'otp_verified',
            'phone_mask' => '****3456', 'full_name' => 'Char Public', 'variable_symbol' => '4242', 'has_pdf' => false, 'addon' => 0, 'addon_signed' => 0,
        ]);
        $this->postJson(route('sign.info'), ['t' => 'x.y'])->assertStatus(401)->assertExactJson(['error' => 'Neplatný nebo expirovaný odkaz.']);

        $res = $this->get(route('sign.preview', ['t' => $t]))->assertOk();
        $this->assertSame(['application/pdf', 'inline; filename="contract-preview.pdf"', '%PDF-preview-renderContractPdf'],
            [$res->headers->get('Content-Type'), $res->headers->get('Content-Disposition'), $res->getContent()]);
        $this->get(route('sign.preview', ['t' => 'x']))->assertStatus(401);
        $this->get(route('sign.show', ['t' => $t]))->assertOk()->assertViewIs('contracts.sign')->assertViewHas('token', $t);
    }

    public function test_otp(): void
    {
        $t = $this->token();
        $this->postJson(route('sign.otp.send'), ['t' => $t])->assertStatus(400)->assertExactJson(['error' => 'Chybí telefon.']);
        $this->postJson(route('sign.otp.send'), ['t' => $t, 'phone' => '777'])->assertOk()->assertExactJson(['ok' => true]);
        $this->postJson(route('sign.otp.verify'), ['t' => $t, 'otp' => '9'])->assertStatus(422)->assertExactJson(['ok' => false, 'error' => 'Špatný kód']);
        $this->postJson(route('sign.otp.verify'), ['t' => $t, 'otp' => '1234'])->assertOk();
    }

    public function test_finalizace_smlouvy(): void
    {
        $t = $this->token();
        $res = $this->postJson(route('sign.finalize'), ['t' => $t])->assertOk();
        $sha = hash('sha256', '%PDF-renderContractPdf');
        $this->assertSame([true, $sha], [$res->json('ok'), $res->json('pdf_sha256')]);

        $c = DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->first();
        $this->assertSame(['signed', hash('sha256', '%PDF-renderContractPdf', true)], [$c->status, $c->pdf_sha256]);
        $this->assertNotNull($c->signed_at);
        $this->assertSame('ne', DB::connection('contracts')->table('contract_parties')->where('contract_id', $this->contractId)->value('termination'));
        $this->assertSame([['sha256' => $sha]], $this->events('pdf_generated')->all());

        $this->assertSame([2, 1], [(int) DB::table('members')->where('id', $this->memberId)->value('type'), (int) DB::table('members')->where('id', $this->memberId)->value('registration')]);
        $this->assertSame([['member_id' => $this->memberId, 'old_type' => 18, 'new_type' => 2, 'registration' => 1]], $this->events('member_activated')->all());

        $mail = DB::table('email_queues')->where('to', 'charpublic@example.invalid')->orderByDesc('id')->first();
        $this->assertSame('Podepsaná smlouva ' . $this->contractNo . ' - PVfree.net', $mail->subject);
        $this->assertSame(['smlouva-' . $this->contractId . '.pdf'], DB::table('email_queue_attachments')->where('email_queue_id', $mail->id)->pluck('name')->all());
        $this->assertSame([['to' => 'charpublic@example.invalid', 'attachments' => ['smlouva-' . $this->contractId . '.pdf']]], $this->events('post_sign_email_sent')->all());

        $dl = $this->dlRow($res->json('download_url'));
        $this->assertSame(['contract', null, 1, null], [$dl->file_type, $dl->addon_id, (int) $dl->max_uses, $dl->bind_ip]);
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, strtotime($dl->expires_at), 5);

        // jednorázové stažení
        $this->get($res->json('download_url'))->assertOk();
        $this->get($res->json('download_url'))->assertStatus(410);
    }

    public function test_finalizace_odmitnuta_a_bez_emailu(): void
    {
        DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->update(['status' => 'draft']);
        $t = $this->token();
        $this->postJson(route('sign.finalize'), ['t' => $t])->assertStatus(409)->assertExactJson(['error' => 'Smlouva nebyla ověřena.']);
        $this->postJson(route('sign.finalize'), ['t' => 'x'])->assertStatus(401);

        Setting::set('contract_email_attachments_enabled', 0);
        DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->update(['status' => 'otp_verified']);
        $this->postJson(route('sign.finalize'), ['t' => $t])->assertOk();
        $this->assertSame(0, $this->events('post_sign_email_sent')->count(), 'vypnuté přílohy → žádný e-mail');
    }

    public function test_podepsana_smlouva_se_znovu_negeneruje(): void
    {
        $pdf = $this->file('puvodni');
        $signedAt = now()->subMonth()->startOfSecond();
        DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->update([
            'status' => 'signed', 'signed_at' => $signedAt, 'pdf_path' => $pdf, 'pdf_sha256' => hash('sha256', '%PDF-puvodni', true),
        ]);
        $t = $this->token();

        $res = $this->postJson(route('sign.finalize'), ['t' => $t])->assertOk();
        $this->assertSame([true, hash('sha256', '%PDF-puvodni')], [$res->json('ok'), $res->json('pdf_sha256')]);

        $c = DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->first();
        $this->assertSame([$pdf, $signedAt->toDateTimeString()], [$c->pdf_path, (string) $c->signed_at], 'PDF ani datum podpisu se nepřepsaly');
        $this->assertSame('%PDF-puvodni', file_get_contents($pdf));
        $this->assertSame(0, $this->events('pdf_generated')->count());
        $this->assertSame(0, $this->events('member_activated')->count());
        $this->assertSame(0, $this->events('post_sign_email_sent')->count());
        $this->assertSame('ano', DB::connection('contracts')->table('contract_parties')->where('contract_id', $this->contractId)->value('termination'));
        $this->assertSame(18, (int) DB::table('members')->where('id', $this->memberId)->value('type'), 'člen se znovu neaktivuje');

        $this->assertSame('%PDF-puvodni', $this->get($res->json('download_url'))->assertOk()->streamedContent());

        // podepsaná bez uloženého PDF → nic negenerovat
        DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->update(['pdf_path' => null]);
        $this->postJson(route('sign.finalize'), ['t' => $t])->assertStatus(409)->assertExactJson(['error' => 'Smlouva je již podepsaná.']);
        $this->assertSame(0, $this->events('pdf_generated')->count());
    }

    public function test_soubezny_podpis_dostane_409(): void
    {
        $t = $this->token();
        $lock = \Illuminate\Support\Facades\Cache::lock('contract_sign:contract:' . $this->contractId, 120);
        $this->assertTrue($lock->get());
        $this->postJson(route('sign.finalize'), ['t' => $t])->assertStatus(409)
            ->assertExactJson(['error' => 'Podpis se právě zpracovává, zkuste to za chvíli.']);
        $this->assertSame('otp_verified', DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->value('status'));
        $lock->release();

        $this->postJson(route('sign.finalize'), ['t' => $t])->assertOk();
    }

    public function test_podepsany_stary_dodatek_se_znovu_nepodepise(): void
    {
        $pdf = $this->file('dodatek-puvodni');
        DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->update([
            'addon' => 1, 'addon_signed' => 1, 'addon_signed_at' => now()->subWeek(), 'addon_pdf_path' => $pdf,
        ]);
        $t = $this->token();

        $this->postJson(route('sign.addon.otp.verify'), ['t' => $t, 'otp' => '1234'])->assertStatus(409)
            ->assertExactJson(['error' => 'Dodatek je již podepsán.']);
        $this->assertSame($pdf, DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->value('addon_pdf_path'));
        $this->assertSame(0, $this->events('addon_generated')->count());
        $this->assertSame(0, $this->events('addon_post_sign_email_sent')->count());
    }

    public function test_stary_dodatek_a_zrusena_vypoved(): void
    {
        DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->update(['addon' => 1]);
        $t = $this->token();
        $this->postJson(route('sign.addon.otp.send'), ['t' => $t])->assertOk();
        $this->postJson(route('sign.addon.otp.verify'), ['t' => $t, 'otp' => '0'])->assertStatus(422);
        $res = $this->postJson(route('sign.addon.otp.verify'), ['t' => $t, 'otp' => '1234'])->assertOk();
        $c = DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->first();
        $this->assertSame(1, (int) $c->addon_signed);
        $this->assertSame([['sha256' => hash('sha256', '%PDF-renderAddonPdf')]], $this->events('addon_generated')->all());
        $mail = DB::table('email_queues')->where('to', 'charpublic@example.invalid')->orderByDesc('id')->first();
        $this->assertSame('Podepsaný dodatek ke smlouvě ' . $this->contractNo . ' - PVfree.net', $mail->subject);
        $this->assertSame(['dodatek-' . $this->contractNo . '.pdf'], DB::table('email_queue_attachments')->where('email_queue_id', $mail->id)->pluck('name')->all());
        $this->assertSame([['to' => 'charpublic@example.invalid']], $this->events('addon_post_sign_email_sent')->all());
        $this->assertSame('addon', $this->dlRow($res->json('download_url'))->file_type);

        $res = $this->get(route('sign.addon.preview', ['t' => $t]))->assertOk();
        $this->assertSame('inline; filename="addon-preview.pdf"', $res->headers->get('Content-Disposition'));

        // Veřejná „výpověď" (pozůstatek převodu členů na zákazníky) je zrušená:
        // adresa neexistuje, nic se nevygeneruje ani nepřepíše.
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('sign.terminate'));
        $this->postJson('/sign/terminate', ['t' => $t])->assertNotFound();
        $this->assertSame(0, $this->events('termination_generated')->count());
        $this->assertSame('ano', DB::connection('contracts')->table('contract_parties')->where('contract_id', $this->contractId)->value('termination'), 'příznak strany beze změny');
    }

    public function test_stazeni_tokenem(): void
    {
        $this->get(route('sign.download'))->assertStatus(400);
        $this->get(route('sign.download', ['t' => 'neni']))->assertStatus(410);

        $file = $this->file('dl');
        DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->update(['pdf_path' => $file]);
        $mk = fn (array $r) => tap(bin2hex(random_bytes(32)), fn ($tok) => DB::connection('contracts')->table('download_tokens')->insert($r + [
            'token' => $tok, 'contract_id' => $this->contractId, 'file_type' => 'contract', 'expires_at' => now()->addHour(), 'max_uses' => 1, 'created_at' => now(),
        ]));

        $this->get(route('sign.download', ['t' => $mk(['expires_at' => now()->subMinute()])]))->assertStatus(410);
        $this->get(route('sign.download', ['t' => $mk(['bind_ip' => '203.0.113.9'])]))->assertStatus(403);
        $this->get(route('sign.download', ['t' => $mk(['file_type' => 'addon'])]))->assertStatus(404);
        $res = $this->get(route('sign.download', ['t' => $ok = $mk([])]))->assertOk();
        $this->assertSame('inline; filename="' . basename($file) . '"', $res->headers->get('Content-Disposition'));
        $this->assertNotNull(DB::connection('contracts')->table('download_tokens')->where('token', $ok)->value('used_at'));

        $addon = (int) DB::connection('contracts')->table('contract_addons')->insertGetId(['contract_id' => $this->contractId, 'type' => 'connection_point',
            'effective_date' => now()->toDateString(), 'status' => 'signed', 'pdf_path' => $file]);
        $this->get(route('sign.download', ['t' => $mk(['file_type' => 'connection_point_addon', 'addon_id' => $addon])]))->assertOk();
    }

    // ── dodatky tarif / služba / místo ───────────────────────────────────────

    public function test_tri_typy_dodatku(): void
    {
        DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->update(['status' => 'signed']);
        $fee = (int) DB::table('fees')->insertGetId(['readonly' => 0, 'fee' => 50, 'from' => '2000-01-01', 'to' => '9999-12-31',
            'type_id' => (int) DB::table('enum_types')->whereRaw("LOWER(value) = 'additional service'")->value('id'), 'name' => 'Char služba', 'special_type_id' => null]);
        $subnet = (int) DB::table('subnets')->insertGetId(['name' => 'char ps', 'network_address' => '10.250.19.0', 'netmask' => '255.255.255.0']);
        $speed  = (int) DB::table('speed_classes')->insertGetId(['name' => 'Char PS', 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => 77]);
        $place  = (int) DB::table('allowed_subnets')->insertGetId(['member_id' => $this->memberId, 'subnet_id' => $subnet, 'enabled' => 1, 'charged' => 0, 'speed_class_id' => $speed]);

        $this->actingAs(\App\Models\User::find((int) DB::table('users')->where('type', 1)->value('id')));
        $cases = [
            [app(\App\Domains\Contracts\Actions\CreateTariffAddonAction::class)->handle($this->contractId, $speed), 'issueTariffAddonLink', 'tariff_addon', 'dodatek', 'změna tarifu', 'dodatek-tarif-', 'tariff_addon', 'Tariff'],
            [app(\App\Domains\Contracts\Actions\CreateServiceAddonAction::class)->handle($this->contractId, $fee), 'issueServiceAddonLink', 'service_addon', 'dodatek-sluzba', 'dodatečná služba', 'dodatek-sluzba-', 'service_addon', 'Service'],
            [app(\App\Domains\Contracts\Actions\CreateConnectionPointAddonAction::class)->handle($this->contractId, $place, 'add', 'Adresa 9'), 'issueConnectionPointAddonLink', 'connection_point', 'dodatek-misto', 'přípojné místo', 'dodatek-misto-', 'connection_point_addon', 'ConnectionPoint'],
        ];
        $tokens = [];
        foreach ($cases as $i => $c) {
            parse_str((string) parse_url($this->issueAddonLink($c[0]->id)['url'], PHP_URL_QUERY), $q);
            $tokens[$i] = $q['t'];
        }
        $contractToken = $this->token();
        auth()->logout();

        foreach ($cases as $i => [$addon, , $route, $previewName, $label, $attPrefix, $fileType, $render]) {
            $t = $tokens[$i];
            $info = $this->postJson(route("sign.{$route}.info"), ['t' => $t])->assertOk()->json();
            $this->assertSame([$this->contractNo, 'Char Public', '4242', '****3456', 0],
                [$info['contract_no'], $info['full_name'], $info['variable_symbol'], $info['phone_mask'], $info['signed']], $route);
            $this->get(route("sign.{$route}.show", ['t' => $t]))->assertOk()->assertViewHas('token', $t);

            $res = $this->get(route("sign.{$route}.preview", ['t' => $t]))->assertOk();
            $this->assertSame(['inline; filename="' . $previewName . '-preview.pdf"', "%PDF-preview-render{$render}AddonPdf"], [$res->headers->get('Content-Disposition'), $res->getContent()]);

            $this->postJson(route("sign.{$route}.otp.verify"), ['t' => $contractToken, 'otp' => '1234'])->assertStatus(401);
            $this->postJson(route("sign.{$route}.otp.verify"), ['t' => $t, 'otp' => '0'])->assertStatus(422);
            $res = $this->postJson(route("sign.{$route}.otp.verify"), ['t' => $t, 'otp' => '1234'])->assertOk();
            $this->assertSame('signed', ContractAddon::find($addon->id)->status);
            $dl = $this->dlRow($res->json('download_url'));
            $this->assertSame([$fileType, $addon->id], [$dl->file_type, (int) $dl->addon_id]);

            $mail = DB::table('email_queues')->where('to', 'charpublic@example.invalid')->where('subject', "Podepsaný dodatek ({$label}) ke smlouvě {$this->contractNo} - PVfree.net")->first();
            $this->assertNotNull($mail, $label);
            $this->assertSame([$attPrefix . $this->contractNo . '.pdf'], DB::table('email_queue_attachments')->where('email_queue_id', $mail->id)->pluck('name')->all());
            $this->assertSame([['to' => 'charpublic@example.invalid', 'addon_id' => $addon->id]], $this->events("{$fileType}_post_sign_email_sent")->all());

            $this->postJson(route("sign.{$route}.otp.verify"), ['t' => $t, 'otp' => '1234'])->assertStatus(409)->assertExactJson(['error' => 'Dodatek je již podepsán.']);
            $this->assertSame(1, $this->postJson(route("sign.{$route}.info"), ['t' => $t])->json('signed'));
            $res = $this->get(route("sign.{$route}.preview", ['t' => $t]))->assertOk();
            $this->assertSame('inline; filename="' . $previewName . '.pdf"', $res->headers->get('Content-Disposition'), 'podepsaný → uložené PDF');
        }

        $svcInfo = $this->postJson(route('sign.service_addon.info'), ['t' => $tokens[1]])->json();
        $this->assertSame(['Char služba', 50.0, now()->format('d.m.Y')], [$svcInfo['service_name'], (float) $svcInfo['service_price'], $svcInfo['effective_date']]);
        $cpInfo = $this->postJson(route('sign.connection_point.info'), ['t' => $tokens[2]])->json();
        $this->assertSame(['Adresa 9', 'Char PS', 77.0], [$cpInfo['place_name'], $cpInfo['place_speed'], (float) $cpInfo['place_price']]);
        $this->postJson(route('sign.service_addon.info'), ['t' => $tokens[0]])->assertStatus(401, 'token tarifu na službu: typ nesedí');
    }

    public function test_chyba_podpisu_neprozradi_detail(): void
    {
        $pdf = $this->createMock(PdfService::class);
        $pdf->method('renderContractPdf')->willThrowException(new \RuntimeException('SQLSTATE /var/www/tajna/cesta'));
        $this->app->instance(PdfService::class, $pdf);
        $t = $this->token();

        $this->postJson(route('sign.finalize'), ['t' => $t])->assertStatus(500)->assertExactJson(['error' => 'Server error']);
    }
}
