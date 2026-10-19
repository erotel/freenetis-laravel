<?php

namespace Tests\Characterization\Billing;

use Illuminate\Support\Facades\DB;
use Tests\Characterization\Catalog\CatalogTestCase;

/**
 * Charakterizace bankovních pohybů (BankTransferController) před přesunem do
 * domény Billing: pohyby účtu, neidentifikované platby (z 684000 bez člena,
 * výchozí 30 dní / vše / rozsah), formulář a založení vrácení (koncept
 * odchozí platby, jen jednou, audit), ACL.
 */
class BankTransfersTest extends CatalogTestCase
{
    private int $org;
    private int $sender;
    private int $income;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = (int) DB::table('bank_accounts')->insertGetId(['name' => 'Org', 'account_nr' => (string) random_int(10000000, 99999999), 'bank_nr' => '2010', 'member_id' => 1]);
        $this->sender = (int) DB::table('bank_accounts')->insertGetId(['name' => "Odesílatel {$this->tag}", 'account_nr' => '123456', 'bank_nr' => '0800']);
        $this->income = (int) DB::table('accounts')->where('account_attribute_id', 684000)->value('id')
            ?: (int) DB::table('accounts')->insertGetId(['name' => 'prijem', 'account_attribute_id' => 684000, 'balance' => 0]);
    }

    /** Bankovní pohyb s účetním převodem z 684000. */
    private function payment(string $datetime, ?int $memberId = null, float $amount = 500, ?int $statement = null): int
    {
        $tr = (int) DB::table('transfers')->insertGetId(['origin_id' => $this->income, 'destination_id' => null, 'member_id' => $memberId, 'amount' => $amount,
            'datetime' => $datetime, 'creation_datetime' => $datetime, 'text' => 'char']);
        $statement ??= (int) DB::table('bank_statements')->insertGetId(['bank_account_id' => $this->org, 'from' => $datetime, 'to' => $datetime]);
        return (int) DB::table('bank_transfers')->insertGetId(['origin_id' => $this->sender, 'destination_id' => $this->org, 'transfer_id' => $tr,
            'bank_statement_id' => $statement, 'variable_symbol' => 12345]);
    }

    private function ids($res): array
    {
        return collect($res->viewData('transfers')->items())->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_pohyby_uctu_a_neidentifikovane(): void
    {
        $recent = $this->payment(now()->subDays(2)->toDateTimeString());
        $old = $this->payment(now()->subDays(60)->toDateTimeString());
        $identified = $this->payment(now()->subDay()->toDateTimeString(), 1);

        $this->assertSame([$identified, $old, $recent], $this->ids($this->as()->get(route('bank_transfers.by_account', $this->org))->assertOk()));
        $this->as()->get(route('bank_transfers.by_account', 999999999))->assertNotFound();

        $res = $this->as()->get(route('bank_transfers.unidentified'))->assertOk();
        $ids = $this->ids($res);
        $this->assertTrue(in_array($recent, $ids, true) && !in_array($old, $ids, true) && !in_array($identified, $ids, true));
        $this->assertSame(now()->subDays(30)->toDateString(), $res->viewData('from'));
        $this->assertContains($old, $this->ids($this->as()->get(route('bank_transfers.unidentified', ['all' => 1]))));
        $range = $this->as()->get(route('bank_transfers.unidentified', ['from' => now()->subDays(61)->toDateString(), 'to' => now()->subDays(59)->toDateString()]));
        $this->assertSame([$old], array_values(array_intersect($this->ids($range), [$old, $recent])));
    }

    public function test_vraceni(): void
    {
        $bt = $this->payment(now()->toDateTimeString());
        $tr = (int) DB::table('bank_transfers')->where('id', $bt)->value('transfer_id');

        $form = $this->as()->get(route('bank-transfers.refund.form', $bt))->assertOk()->assertViewIs('bank_transfers.refund_form');
        $this->assertSame(['bank_account_id' => $this->org, 'target_account' => '123456/0800', 'target_name' => "Odesílatel {$this->tag}", 'amount' => 500.0,
            'currency' => 'CZK', 'variable_symbol' => 12345, 'message' => "Vrácení neidentifikované platby #{$tr}", 'reason' => 'unidentified_refund'],
            array_merge($form->viewData('prefill'), ['amount' => (float) $form->viewData('prefill')['amount'], 'variable_symbol' => (int) $form->viewData('prefill')['variable_symbol']]));

        $this->as()->post(route('bank-transfers.refund.store', $bt), ['bank_account_id' => $this->org, 'amount' => '450', 'currency' => 'CZK', 'message' => 'vracime'])
            ->assertRedirect(route('bank_transfers.unidentified'))->assertSessionHas('success', 'Odchozí platba (vrácení) byla vytvořena jako koncept.');
        $op = DB::table('outgoing_payments')->where('transfer_id', $tr)->first();
        $this->assertSame([$this->org, '123456/0800', 450.0, 'draft', 'unidentified_refund', '', 'vracime', $this->adminId],
            [(int) $op->bank_account_id, $op->target_account, (float) $op->amount, $op->status, $op->reason, $op->variable_symbol, $op->message, (int) $op->created_by]);
        $audit = DB::table('audit_logs')->where('auditable_type', 'outgoing_payments')->where('auditable_id', $tr)->orderByDesc('id')->first();
        $this->assertSame(['reason' => 'unidentified_refund', 'amount' => '450', 'target_account' => '123456/0800'], json_decode($audit->new_values, true));

        $this->as()->from(route('bank-transfers.refund.form', $bt))->post(route('bank-transfers.refund.store', $bt), ['bank_account_id' => $this->org, 'amount' => '1', 'currency' => 'CZK'])
            ->assertSessionHas('error', 'Pro tento převod již existuje odchozí platba (stav: draft).');
        $this->as()->post(route('bank-transfers.refund.store', $bt), ['amount' => '0'])->assertSessionHasErrors(['bank_account_id', 'amount', 'currency']);

        $identified = $this->payment(now()->toDateTimeString(), 1);
        $this->as()->get(route('bank-transfers.refund.form', $identified))->assertForbidden();
        $this->as()->post(route('bank-transfers.refund.store', $identified), ['bank_account_id' => $this->org, 'amount' => '1', 'currency' => 'CZK'])->assertForbidden();

        $noTransfer = (int) DB::table('bank_transfers')->insertGetId(['origin_id' => $this->sender, 'destination_id' => $this->org, 'transfer_id' => null]);
        $this->as()->post(route('bank-transfers.refund.store', $noTransfer), ['bank_account_id' => $this->org, 'amount' => '1', 'currency' => 'CZK'])
            ->assertSessionHas('error', 'K tomuto bankovnímu pohybu chybí účetní převod — vrácení nelze provést.');
        $noOrigin = $this->payment(now()->toDateTimeString());
        DB::table('bank_transfers')->where('id', $noOrigin)->update(['origin_id' => null]);
        $this->as()->post(route('bank-transfers.refund.store', $noOrigin), ['bank_account_id' => $this->org, 'amount' => '1', 'currency' => 'CZK'])
            ->assertSessionHas('error', 'Neznámý odesílatel platby — vrácení nelze bezpečně provést (chybí protiúčet).');
        $this->as()->get(route('bank-transfers.refund.form', 999999999))->assertNotFound();
    }

    public function test_prava(): void
    {
        $bt = $this->payment(now()->toDateTimeString());
        $this->acl(['view_all/Accounts_Controller/bank_transfers', 'view_all/Accounts_Controller/unidentified_transfers', 'edit_all/Accounts_Controller/unidentified_transfers']);
        foreach ([route('bank_transfers.by_account', $this->org), route('bank_transfers.unidentified'), route('bank-transfers.refund.form', $bt)] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        $this->as()->post(route('bank-transfers.refund.store', $bt), [])->assertForbidden();
    }
}
