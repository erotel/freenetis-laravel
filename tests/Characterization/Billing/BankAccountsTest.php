<?php

namespace Tests\Characterization\Billing;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\Characterization\Catalog\CatalogTestCase;
use Tests\Support\Fake;

/**
 * Charakterizace bankovních účtů (BankAccountController) a pravidel
 * automatického stahování výpisů (BankAccountAutoDownloadController) před
 * přesunem do domény Billing: seznam (sdružení / členové, hledání, příznak
 * pravidel), detail, úprava IBAN/SWIFT/FIO tokenu, pravidla (atributy podle
 * typu), ACL.
 */
class BankAccountsTest extends CatalogTestCase
{
    private function account(int $memberId, array $over = []): int
    {
        return (int) DB::table('bank_accounts')->insertGetId($over + ['name' => "Char {$this->tag}", 'member_id' => $memberId,
            'account_nr' => (string) random_int(10000000, 99999999), 'bank_nr' => '2010']);
    }

    public function test_seznam_detail_uprava(): void
    {
        $org = $this->account(1);
        $mem = $this->account((int) DB::table('members')->where('id', '>', 1)->value('id'), ['IBAN' => "CZ{$this->tag}"]);
        DB::table('bank_accounts_automatical_downloads')->insert(['bank_account_id' => $org, 'type' => 5, 'attribute' => null, 'email_enabled' => 0, 'sms_enabled' => 0]);

        $res = $this->as()->get(route('bank_accounts.index', ['q' => $this->tag]))->assertOk();
        $this->assertSame([[$org], [$mem]], [$res->viewData('associationAccounts')->pluck('id')->map(fn ($i) => (int) $i)->all(), $res->viewData('memberAccounts')->pluck('id')->map(fn ($i) => (int) $i)->all()]);
        $this->assertSame([$org => true], $res->viewData('autoDownFlags'));
        $this->assertSame([$mem], $this->as()->get(route('bank_accounts.index', ['q' => "CZ{$this->tag}"]))->viewData('memberAccounts')->pluck('id')->map(fn ($i) => (int) $i)->all());

        $show = $this->as()->get(route('bank_accounts.show', $org))->assertOk();
        $this->assertSame([false, true, true], [$show->viewData('hasFioToken'), $show->viewData('canEdit'), $show->viewData('canManageAutoDown')]);
        $this->as()->get(route('bank_accounts.show', 999999999))->assertNotFound();

        $token = Fake::secret('fio-token');
        $this->as()->get(route('bank_accounts.edit', $org))->assertOk()->assertViewHas('fioToken', '');
        $this->as()->put(route('bank_accounts.update', $org), ['IBAN' => 'CZ65', 'SWIFT' => 'FIOBCZPP', 'fio_token' => $token])
            ->assertRedirect(route('bank_accounts.show', $org))->assertSessionHas('success', 'Bankovní účet byl uložen.');
        $this->assertSame(['CZ65', 'FIOBCZPP', $token], [DB::table('bank_accounts')->where('id', $org)->value('IBAN'), DB::table('bank_accounts')->where('id', $org)->value('SWIFT'), Setting::get("fio_api_token_bank_account_{$org}")]);
        $this->assertTrue($this->as()->get(route('bank_accounts.show', $org))->viewData('hasFioToken'));
        $this->as()->put(route('bank_accounts.update', $org), ['SWIFT' => str_repeat('X', 12)])->assertSessionHasErrors('SWIFT');
        $this->as()->put(route('bank_accounts.update', 999999999), [])->assertNotFound();
    }

    public function test_pravidla_stahovani(): void
    {
        $ba = $this->account(1);
        $this->as()->post(route('bank_accounts.auto_downloads.store', $ba), ['type' => 1, 'attribute' => [26, 13], 'email_enabled' => '1'])
            ->assertRedirect(route('bank_accounts.auto_downloads', $ba))->assertSessionHas('success', 'Pravidlo bylo přidáno.');
        $this->as()->post(route('bank_accounts.auto_downloads.store', $ba), ['type' => 5]);
        $rows = DB::table('bank_accounts_automatical_downloads')->where('bank_account_id', $ba)->orderBy('id')->get(['type', 'attribute', 'email_enabled', 'sms_enabled'])
            ->map(fn ($r) => [(int) $r->type, $r->attribute, (int) $r->email_enabled, (int) $r->sms_enabled])->all();
        $this->assertSame([[1, '26/13', 1, 0], [5, null, 0, 0]], $rows);
        $this->as()->post(route('bank_accounts.auto_downloads.store', $ba), ['type' => 1, 'attribute' => [32, 13]])->assertSessionHasErrors('attribute.0');
        $this->as()->post(route('bank_accounts.auto_downloads.store', $ba), ['type' => 9])->assertStatus(422);
        $this->as()->post(route('bank_accounts.auto_downloads.store', 999999999), ['type' => 5])->assertNotFound();

        $res = $this->as()->get(route('bank_accounts.auto_downloads', $ba))->assertOk()->assertViewIs('bank_accounts.auto_downloads');
        $this->assertCount(2, $res->viewData('rules'));
        $id = (int) $res->viewData('rules')->first()->id;
        $this->as()->delete(route('bank_accounts.auto_downloads.destroy', $id))->assertRedirect(route('bank_accounts.auto_downloads', $ba))->assertSessionHas('success', 'Pravidlo bylo smazáno.');
        $this->assertCount(1, DB::table('bank_accounts_automatical_downloads')->where('bank_account_id', $ba)->get());
        $this->as()->delete(route('bank_accounts.auto_downloads.destroy', $id))->assertNotFound();
    }

    public function test_prava(): void
    {
        $ba = $this->account(1);
        $rule = (int) DB::table('bank_accounts_automatical_downloads')->insertGetId(['bank_account_id' => $ba, 'type' => 5, 'email_enabled' => 0, 'sms_enabled' => 0]);
        $this->acl(['view_all/Accounts_Controller/bank_accounts', 'edit_all/Accounts_Controller/bank_accounts', 'view_all/Accounts_Controller/bank_account_auto_down_config',
            'new_all/Accounts_Controller/bank_account_auto_down_config', 'delete_all/Accounts_Controller/bank_account_auto_down_config']);
        foreach ([route('bank_accounts.index'), route('bank_accounts.show', $ba), route('bank_accounts.edit', $ba), route('bank_accounts.auto_downloads', $ba)] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        $this->as()->put(route('bank_accounts.update', $ba), [])->assertForbidden();
        $this->as()->post(route('bank_accounts.auto_downloads.store', $ba), ['type' => 5])->assertForbidden();
        $this->as()->delete(route('bank_accounts.auto_downloads.destroy', $rule))->assertForbidden();
        $this->assertTrue(DB::table('bank_accounts_automatical_downloads')->where('id', $rule)->exists());
    }
}
