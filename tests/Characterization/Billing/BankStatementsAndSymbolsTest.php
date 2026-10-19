<?php

namespace Tests\Characterization\Billing;

use Illuminate\Support\Facades\DB;
use Tests\Characterization\Catalog\CatalogTestCase;

/**
 * Charakterizace bankovních výpisů (BankStatementController) a variabilních
 * symbolů (VariableSymbolController) před přesunem do domény Billing:
 * výpisy účtu (nejnovější první), smazání výpisu s pohyby (soft-delete +
 * audit), VS účtu, přidání (číslo 1–10 číslic, jedinečný), smazání, ACL.
 */
class BankStatementsAndSymbolsTest extends CatalogTestCase
{
    private function bankAccount(): int
    {
        return (int) DB::table('bank_accounts')->insertGetId(['name' => "Char {$this->tag}", 'account_nr' => (string) random_int(10000000, 99999999), 'bank_nr' => '2010']);
    }

    private function statement(int $ba, string $from): int
    {
        return (int) DB::table('bank_statements')->insertGetId(['bank_account_id' => $ba, 'user_id' => $this->adminId, 'from' => $from, 'to' => $from, 'type' => 'json']);
    }

    private function freeVs(): string
    {
        do {
            $vs = (string) random_int(10000000, 99999999);
        } while (DB::table('variable_symbols')->where('variable_symbol', $vs)->exists());
        return $vs;
    }

    public function test_vypisy(): void
    {
        $ba = $this->bankAccount();
        $old = $this->statement($ba, '2026-01-01');
        $new = $this->statement($ba, '2026-02-01');
        $res = $this->as()->get(route('bank_statements.by_account', $ba))->assertOk()->assertViewIs('bank_statements.show_by_account');
        $this->assertSame([$new, $old], collect($res->viewData('statements')->items())->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame($ba, (int) $res->viewData('account')->id);
        $this->as()->get(route('bank_statements.by_account', 999999999))->assertNotFound();

        $bt = (int) DB::table('bank_transfers')->insertGetId(['bank_statement_id' => $old, 'transaction_code' => random_int(1, PHP_INT_MAX >> 8)]);
        $this->as()->delete(route('bank_statements.destroy', $old))->assertRedirect(route('bank_statements.by_account', $ba))->assertSessionHas('success', 'Výpis byl smazán.');
        $this->assertFalse(DB::table('bank_statements')->where('id', $old)->exists());
        $this->assertNotNull(DB::table('bank_transfers')->where('id', $bt)->value('deleted_at'), 'pohyby soft-delete');
        $audit = DB::table('audit_logs')->where('auditable_type', 'bank_transfers')->where('auditable_id', $old)->orderByDesc('id')->first();
        $this->assertSame(['bank_statement_id' => $old, 'bank_transfers_deleted' => 1], json_decode($audit->old_values, true));
        $this->as()->delete(route('bank_statements.destroy', $old))->assertNotFound();
    }

    public function test_variabilni_symboly(): void
    {
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => 1, 'name' => 'c', 'account_attribute_id' => 221100, 'balance' => 0]);
        $vs = $this->freeVs();
        $this->as()->post(route('variable_symbols.store', $acc), ['variable_symbol' => $vs])
            ->assertRedirect(route('variable_symbols.by_account', $acc))->assertSessionHas('success', 'Variabilní symbol byl úspěšně přidán.');
        $this->as()->post(route('variable_symbols.store', $acc), ['variable_symbol' => $vs])->assertSessionHasErrors(['variable_symbol' => 'Tento variabilní symbol je již použit.']);
        $this->as()->post(route('variable_symbols.store', $acc), ['variable_symbol' => '12345678901'])->assertSessionHasErrors(['variable_symbol' => 'Variabilní symbol musí být číslo o délce 1–10 číslic.']);
        $this->as()->post(route('variable_symbols.store', 999999999), ['variable_symbol' => $this->freeVs()])->assertNotFound();

        $res = $this->as()->get(route('variable_symbols.by_account', $acc))->assertOk()->assertViewIs('variable_symbols.show_by_account');
        $this->assertSame([$vs], $res->viewData('symbols')->pluck('variable_symbol')->all());
        $this->assertTrue($res->viewData('canAdd') && $res->viewData('canDelete'));
        $this->as()->get(route('variable_symbols.by_account', 999999999))->assertNotFound();

        $id = (int) DB::table('variable_symbols')->where('variable_symbol', $vs)->value('id');
        $this->as()->delete(route('variable_symbols.destroy', $id))->assertRedirect(route('variable_symbols.by_account', $acc))->assertSessionHas('success', 'Variabilní symbol byl smazán.');
        $this->assertFalse(DB::table('variable_symbols')->where('id', $id)->exists());
        $this->as()->delete(route('variable_symbols.destroy', $id))->assertNotFound();
    }

    public function test_prava(): void
    {
        $ba = $this->bankAccount();
        $st = $this->statement($ba, '2026-01-01');
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => 1, 'name' => 'c', 'account_attribute_id' => 221100, 'balance' => 0]);
        $vs = (int) DB::table('variable_symbols')->insertGetId(['account_id' => $acc, 'variable_symbol' => $this->freeVs()]);
        $this->acl(['view_all/Accounts_Controller/bank_statements', 'delete/Accounts_Controller/bank_statements',
            'view_all/Variable_Symbols_Controller/variable_symbols', 'new_all/Variable_Symbols_Controller/variable_symbols', 'delete_all/Variable_Symbols_Controller/variable_symbols']);
        $this->as()->get(route('bank_statements.by_account', $ba))->assertForbidden();
        $this->as()->delete(route('bank_statements.destroy', $st))->assertForbidden();
        $this->as()->get(route('variable_symbols.by_account', $acc))->assertForbidden();
        $this->as()->post(route('variable_symbols.store', $acc), ['variable_symbol' => $this->freeVs()])->assertForbidden();
        $this->as()->delete(route('variable_symbols.destroy', $vs))->assertForbidden();
        $this->assertTrue(DB::table('bank_statements')->where('id', $st)->exists() && DB::table('variable_symbols')->where('id', $vs)->exists());
    }
}
