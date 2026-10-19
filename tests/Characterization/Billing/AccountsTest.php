<?php

namespace Tests\Characterization\Billing;

use App\Models\AccountAttribute;
use Illuminate\Support\Facades\DB;
use Tests\Characterization\Catalog\CatalogTestCase;

/**
 * Charakterizace účetních účtů (AccountController) před přesunem do domény
 * Billing: seznam (typ kreditní / projektový / ostatní, hledání, řazení),
 * detail s obraty, založení projektového účtu, úprava názvu, ACL.
 */
class AccountsTest extends CatalogTestCase
{
    private function account(array $over = []): int
    {
        return (int) DB::table('accounts')->insertGetId($over + ['member_id' => null, 'name' => "Char {$this->tag}", 'account_attribute_id' => AccountAttribute::PROJECT_ATTRIBUTE_ID, 'balance' => 0]);
    }

    private function ids($res): array
    {
        return collect($res->viewData('accounts')->items())->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_seznam_a_detail(): void
    {
        $project = $this->account(['balance' => 5]);
        $credit = $this->account(['member_id' => 1, 'account_attribute_id' => 221100, 'name' => "Char {$this->tag} k", 'balance' => 1]);
        $other = $this->account(['account_attribute_id' => 221000, 'name' => "Char {$this->tag} o", 'balance' => 3]);

        $this->assertSame([$credit, $other, $project], $this->ids($this->as()->get(route('accounts.index', ['q' => $this->tag, 'sort' => 'balance']))->assertOk()));
        $this->assertSame([$project, $other, $credit], $this->ids($this->as()->get(route('accounts.index', ['q' => $this->tag, 'sort' => 'balance', 'dir' => 'desc']))));
        $this->assertSame([$credit], $this->ids($this->as()->get(route('accounts.index', ['q' => $this->tag, 'type' => 'credit']))));
        $this->assertSame([$project], $this->ids($this->as()->get(route('accounts.index', ['q' => $this->tag, 'type' => 'project']))));
        $this->assertSame([$other], $this->ids($this->as()->get(route('accounts.index', ['q' => $this->tag, 'type' => 'other']))));
        $this->assertSame([$other], $this->ids($this->as()->get(route('accounts.index', ['q' => (string) $other]))));
        $def = $this->as()->get(route('accounts.index', ['sort' => 'x', 'record_per_page' => 3]));
        $this->assertSame(['id', 'asc', 50, 'all'], [$def->viewData('sort'), $def->viewData('dir'), $def->viewData('perPage'), $def->viewData('type')]);

        $t = fn (?int $from, ?int $to, float $amount) => DB::table('transfers')->insert(['origin_id' => $from, 'destination_id' => $to, 'amount' => $amount, 'datetime' => now(), 'creation_datetime' => now(), 'text' => 'c']);
        $t($other, $project, 100);
        $t($other, $project, 50);
        $t($project, $credit, 30);
        $show = $this->as()->get(route('accounts.show', $project))->assertOk()->assertViewIs('accounts.show');
        $this->assertEqualsWithDelta([150.0, 30.0], [(float) $show->viewData('totalIn'), (float) $show->viewData('totalOut')], 0.001);
        $this->assertTrue($show->viewData('canViewTransfers'));
        $this->as()->get(route('accounts.show', 999999999))->assertNotFound();
    }

    public function test_zalozeni_a_uprava(): void
    {
        $this->as()->get(route('accounts.create'))->assertOk();
        $res = $this->as()->post(route('accounts.store'), ['name' => "Projekt {$this->tag}", 'comment' => 'k']);
        $r = DB::table('accounts')->where('name', "Projekt {$this->tag}")->first();
        $res->assertRedirect(route('accounts.show', $r->id))->assertSessionHas('success', 'Projektový účet byl úspěšně přidán.');
        $this->assertSame([null, AccountAttribute::PROJECT_ATTRIBUTE_ID, 0.0, 'k'], [$r->member_id, (int) $r->account_attribute_id, (float) $r->balance, $r->comment]);
        $this->as()->post(route('accounts.store'), ['name' => 'ab'])->assertSessionHasErrors('name');

        $this->as()->get(route('accounts.edit', $r->id))->assertOk()->assertViewHas('account', fn ($a) => (int) $a->id === (int) $r->id);
        $this->as()->put(route('accounts.update', $r->id), ['name' => "Upr {$this->tag}", 'balance' => 999, 'member_id' => 1])
            ->assertRedirect(route('accounts.show', $r->id))->assertSessionHas('success', 'Účet byl úspěšně upraven.');
        $u = DB::table('accounts')->where('id', $r->id)->first();
        $this->assertSame(["Upr {$this->tag}", 'k', 0.0, null], [$u->name, $u->comment, (float) $u->balance, $u->member_id], 'jen název a komentář');
        $this->as()->get(route('accounts.edit', 999999999))->assertNotFound();
        $this->as()->put(route('accounts.update', 999999999), ['name' => 'abc'])->assertNotFound();
    }

    public function test_prava(): void
    {
        $id = $this->account();
        $this->acl(['view_all/Accounts_Controller/accounts', 'new_all/Accounts_Controller/accounts', 'edit_all/Accounts_Controller/accounts']);
        foreach ([route('accounts.index'), route('accounts.show', $id), route('accounts.create'), route('accounts.edit', $id)] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        $this->as()->post(route('accounts.store'), ['name' => 'abc'])->assertForbidden();
        $this->as()->put(route('accounts.update', $id), ['name' => 'abc'])->assertForbidden();
    }
}
