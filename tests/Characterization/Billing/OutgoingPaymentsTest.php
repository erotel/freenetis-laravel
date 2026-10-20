<?php

namespace Tests\Characterization\Billing;

use App\Domains\Acl\AclService;
use App\Domains\Billing\Bank\FioApiService;
use App\Models\OutgoingPayment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace odchozích plateb (OutgoingPaymentController) před přesunem
 * do domény Billing: seznam (filtr stavu, účty pro export + zda mají FIO
 * token), detail, schválení (jen koncept), zrušení (koncept / schválená),
 * export schválených plateb účtu — s FIO tokenem přímo do FIO API, bez něj
 * stažení XML (importIB); stav exported, poznámka k bankovnímu převodu; ACL.
 */
class OutgoingPaymentsTest extends DatabaseTestCase
{
    private int $adminId;
    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->accountId = (int) DB::table('bank_accounts')->insertGetId(['name' => 'Char FIO', 'member_id' => 1, 'account_nr' => '2900000001', 'bank_nr' => '2010']);
        Setting::set('bank_account_member_type_2', $this->accountId);
        $this->acl();
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function as()
    {
        return $this->actingAs(User::find($this->adminId));
    }

    private function payment(string $status = OutgoingPayment::STATUS_DRAFT, array $over = []): OutgoingPayment
    {
        return OutgoingPayment::create($over + [
            'bank_account_id' => $this->accountId, 'target_account' => '123456789/0100', 'target_name' => 'Char Příjemce',
            'amount' => 150.5, 'currency' => 'CZK', 'variable_symbol' => '7712345', 'message' => 'Vrácení přeplatku',
            'reason' => OutgoingPayment::REASON_TERMINATION, 'status' => $status, 'created_by' => $this->adminId,
        ]);
    }

    private function bankTransfer(?string $comment): int
    {
        $t = (int) DB::table('transfers')->insertGetId(['origin_id' => null, 'destination_id' => null, 'datetime' => now(), 'creation_datetime' => now(),
            'text' => 'char', 'amount' => 150.5, 'type' => 0]);
        DB::table('bank_transfers')->insert(['transfer_id' => $t, 'comment' => $comment]);
        return $t;
    }

    // ── seznam, detail ───────────────────────────────────────────────────────

    public function test_seznam_a_detail(): void
    {
        $d = $this->payment();
        $a = $this->payment(OutgoingPayment::STATUS_APPROVED);
        Setting::set('fio_api_token_bank_account_' . $this->accountId, '__selftest_fio_token');

        $res = $this->as()->get(route('outgoing_payments.index', ['status' => 'approved']))->assertOk()->assertViewIs('outgoing_payments.index');
        $ids = collect($res->viewData('payments')->items())->pluck('id')->all();
        $this->assertContains($a->id, $ids);
        $this->assertNotContains($d->id, $ids);
        $this->assertSame('approved', $res->viewData('currentStatus'));
        $this->assertTrue($res->viewData('canEdit'));
        $this->assertContains(['id' => $this->accountId, 'name' => 'Char FIO', 'full_nr' => '2900000001/2010', 'has_token' => true], $res->viewData('exportBankAccounts')->all());

        $all = collect($this->as()->get(route('outgoing_payments.index', ['status' => 'nesmysl']))->viewData('payments')->items())->pluck('id')->all();
        $this->assertContains($d->id, $all, 'neplatný stav = bez filtru');

        $show = $this->as()->get(route('outgoing_payments.show', $d->id))->assertOk()->assertViewIs('outgoing_payments.show');
        $this->assertSame($d->id, $show->viewData('payment')->id);
        $this->as()->get(route('outgoing_payments.show', 999999999))->assertNotFound();
    }

    // ── schválení, zrušení ───────────────────────────────────────────────────

    public function test_schvaleni_a_zruseni(): void
    {
        $p = $this->payment();
        $this->as()->post(route('outgoing_payments.approve', $p->id))->assertRedirect(route('outgoing_payments.index'))->assertSessionHas('success', 'Platba byla schválena.');
        $this->assertSame(['approved', $this->adminId], [$p->fresh()->status, (int) $p->fresh()->approved_by]);
        $this->as()->from(route('outgoing_payments.show', $p->id))->post(route('outgoing_payments.approve', $p->id))
            ->assertRedirect(route('outgoing_payments.show', $p->id))->assertSessionHas('error', 'Platbu lze schválit pouze ve stavu Koncept.');

        $this->as()->post(route('outgoing_payments.cancel', $p->id))->assertRedirect(route('outgoing_payments.index'))->assertSessionHas('success', 'Platba byla zrušena.');
        $this->assertSame('cancelled', $p->fresh()->status);
        $this->as()->from(route('outgoing_payments.show', $p->id))->post(route('outgoing_payments.cancel', $p->id))
            ->assertSessionHas('error', 'Platbu nelze zrušit v aktuálním stavu.');

        $d = $this->payment();
        $this->as()->post(route('outgoing_payments.cancel', $d->id));
        $this->assertSame('cancelled', $d->fresh()->status, 'koncept jde zrušit');
        $this->as()->post(route('outgoing_payments.approve', 999999999))->assertNotFound();
    }

    // ── export ───────────────────────────────────────────────────────────────

    public function test_export_xml_bez_tokenu(): void
    {
        $t1 = $this->bankTransfer(null);
        $t2 = $this->bankTransfer('původní');
        $p1 = $this->payment(OutgoingPayment::STATUS_APPROVED, ['transfer_id' => $t1, 'message' => 'Vrácení <&>']);
        $p2 = $this->payment(OutgoingPayment::STATUS_APPROVED, ['transfer_id' => $t2, 'variable_symbol' => null, 'currency' => '', 'amount' => 10]);
        $draft = $this->payment();

        $res = $this->as()->post(route('outgoing_payments.export', $this->accountId))->assertOk();
        $this->assertSame('application/xml; charset=utf-8', $res->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('~attachment; filename="platby_2900000001_\d{8}_\d{6}\.xml"~', $res->headers->get('Content-Disposition'));
        $today = now()->format('Y-m-d');
        $tx = fn ($p, $amount, $vs, $cur, $msg) => "<DomesticTransaction>\n<accountFrom>2900000001</accountFrom>\n<currency>{$cur}</currency>\n<amount>{$amount}</amount>\n"
            . "<accountTo>123456789</accountTo>\n<bankCode>0100</bankCode>\n" . ($vs ? "<vs>{$vs}</vs>\n" : '') . "<date>{$today}</date>\n"
            . "<messageForRecipient>{$msg}</messageForRecipient>\n<comment>{$msg}</comment>\n<paymentType>431001</paymentType>\n</DomesticTransaction>\n";
        $this->assertSame('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<Import xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.fio.cz/schema/importIB.xsd">' . "\n<Orders>\n"
            . $tx($p1, '150.50', '7712345', 'CZK', "Vrácení &lt;&amp;&gt; OP #{$p1->id}") . $tx($p2, '10.00', null, 'CZK', "Vrácení přeplatku OP #{$p2->id}")
            . "</Orders>\n</Import>\n", $res->getContent());

        foreach ([$p1, $p2] as $p) {
            $f = $p->fresh();
            $this->assertSame('exported', $f->status);
            $this->assertNotNull($f->exported_at);
            $this->assertStringStartsWith('platby_2900000001_', $f->export_filename);
        }
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(['Čeká se na platbu z banky', 'původní | Čeká se na platbu z banky'],
            [DB::table('bank_transfers')->where('transfer_id', $t1)->value('comment'), DB::table('bank_transfers')->where('transfer_id', $t2)->value('comment')]);

        $this->as()->post(route('outgoing_payments.export', $this->accountId))
            ->assertRedirect(route('outgoing_payments.index'))->assertSessionHas('error', 'Žádné schválené platby k exportu pro tento účet.');
        $this->as()->post(route('outgoing_payments.export', 999999999))->assertNotFound();
    }

    public function test_export_do_fio_api(): void
    {
        Setting::set('fio_api_token_bank_account_' . $this->accountId, '__selftest_fio_token');
        $p = $this->payment(OutgoingPayment::STATUS_APPROVED);
        $fio = $this->mock(FioApiService::class);
        $fio->shouldReceive('importPayments')->once()
            ->with('__selftest_fio_token', \Mockery::on(fn ($xml) => str_contains($xml, "OP #{$p->id}")))->andReturn('<responseImport>ok</responseImport>');

        $this->as()->post(route('outgoing_payments.export', $this->accountId))
            ->assertRedirect(route('outgoing_payments.index'))->assertSessionHas('success', 'Platby byly odeslány do FIO (1 plateb).');
        $f = $p->fresh();
        $this->assertSame(['exported', '<responseImport>ok</responseImport>', null], [$f->status, $f->api_response, $f->export_filename]);

        $q = $this->payment(OutgoingPayment::STATUS_APPROVED);
        $fio = $this->mock(FioApiService::class);
        $fio->shouldReceive('importPayments')->andThrow(new \RuntimeException('HTTP 409'));
        $this->as()->post(route('outgoing_payments.export', $this->accountId))->assertSessionHas('error', 'Chyba FIO API: HTTP 409');
        $this->assertSame('approved', $q->fresh()->status, 'při chybě zůstává schválená');
    }

    public function test_soubezny_export_neodesle_platby_dvakrat(): void
    {
        Setting::set('fio_api_token_bank_account_' . $this->accountId, '__selftest_fio_token');
        $p = $this->payment(OutgoingPayment::STATUS_APPROVED);
        $fio = $this->mock(FioApiService::class);
        $fio->shouldReceive('importPayments')->once()->andReturn('ok');

        $running = \Illuminate\Support\Facades\Cache::lock('outgoing-payments-export:' . $this->accountId, 60);
        $this->assertTrue($running->get(), 'jiný export právě běží');
        $this->as()->post(route('outgoing_payments.export', $this->accountId))
            ->assertSessionHas('error', 'Export plateb z tohoto účtu právě probíhá. Počkejte chvíli a obnovte stránku.');
        $this->assertSame('approved', $p->fresh()->status);
        $running->release();

        $this->as()->post(route('outgoing_payments.export', $this->accountId))->assertSessionHas('success');
        $this->as()->post(route('outgoing_payments.export', $this->accountId))->assertSessionHas('error', 'Žádné schválené platby k exportu pro tento účet.');
        $this->assertSame('exported', $p->fresh()->status);
    }

    // ── ACL ──────────────────────────────────────────────────────────────────

    public function test_prava(): void
    {
        $p = $this->payment();
        $this->acl(['edit_all/Accounts_Controller/unidentified_transfers']);
        $this->assertFalse($this->as()->get(route('outgoing_payments.index'))->viewData('canEdit'));
        $this->as()->post(route('outgoing_payments.approve', $p->id))->assertForbidden();
        $this->as()->post(route('outgoing_payments.cancel', $p->id))->assertForbidden();
        $this->as()->post(route('outgoing_payments.export', $this->accountId))->assertForbidden();
        $this->assertSame('draft', $p->fresh()->status);
        $this->acl(['view_all/Accounts_Controller/bank_transfers']);
        $this->as()->get(route('outgoing_payments.index'))->assertForbidden();
        $this->as()->get(route('outgoing_payments.show', $p->id))->assertForbidden();
    }
}
