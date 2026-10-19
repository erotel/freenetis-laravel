<?php

namespace Tests\Characterization\Billing;

use Illuminate\Support\Facades\DB;
use Tests\Characterization\Catalog\CatalogTestCase;

/**
 * Charakterizace převodů (TransferController) před přesunem do domény
 * Billing: přehled (účet, člen, rozšířené filtry, řazení), převody účtu,
 * detail s navázaným bankovním pohybem a závislými převody, ACL.
 */
class TransfersTest extends CatalogTestCase
{
    private function account(): int
    {
        return (int) DB::table('accounts')->insertGetId(['member_id' => null, 'name' => "Char {$this->tag}", 'account_attribute_id' => 221103, 'balance' => 0]);
    }

    private function transfer(int $from, int $to, float $amount, string $dt, array $over = []): int
    {
        return (int) DB::table('transfers')->insertGetId($over + ['origin_id' => $from, 'destination_id' => $to, 'amount' => $amount, 'datetime' => $dt,
            'creation_datetime' => $dt, 'text' => "char {$this->tag}"]);
    }

    private function ids($res): array
    {
        return collect($res->viewData('transfers')->items())->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_prehled_a_ucet(): void
    {
        [$a, $b, $c] = [$this->account(), $this->account(), $this->account()];
        $t1 = $this->transfer($a, $b, 100, '2099-01-01 10:00:00', ['member_id' => 1]);
        $t2 = $this->transfer($b, $a, 300, '2099-01-03 10:00:00');
        $t3 = $this->transfer($b, $c, 50, '2099-01-02 10:00:00');

        $this->assertSame([$t2, $t1], $this->ids($this->as()->get(route('transfers.index', ['account_id' => $a]))->assertOk()));
        $this->assertSame([$t1, $t2], $this->ids($this->as()->get(route('transfers.index', ['account_id' => $a, 'sort' => 'amount', 'dir' => 'asc']))));
        $this->assertSame([$t1], $this->ids($this->as()->get(route('transfers.index', ['account_id' => $a, 'member_id' => 1]))));
        $f = fn (string $field, string $value) => ['account_id' => $b, 'filters' => [['active' => 1, 'field' => $field, 'value' => $value]]];
        $this->assertSame([$t2], $this->ids($this->as()->get(route('transfers.index', $f('amount_from', '200')))));
        $this->assertSame([$t3, $t1], $this->ids($this->as()->get(route('transfers.index', $f('date_to', '2099-01-02')))));
        $this->assertSame([$t2, $t3, $t1], $this->ids($this->as()->get(route('transfers.index', $f('description', $this->tag)))));
        $def = $this->as()->get(route('transfers.index', ['sort' => 'x', 'record_per_page' => 7]));
        $this->assertSame(['datetime', 'desc', 50], [$def->viewData('sort'), $def->viewData('dir'), $def->viewData('perPage')]);

        $res = $this->as()->get(route('transfers.by_account', $a))->assertOk()->assertViewIs('transfers.show_by_account');
        $this->assertSame([$t2, $t1], $this->ids($res));
        $this->assertSame($a, (int) $res->viewData('account')->id);
        $this->assertSame([$t1, $t2], $this->ids($this->as()->get(route('transfers.by_account', [$a, 'sort' => 'id', 'dir' => 'asc']))));
        $this->as()->get(route('transfers.by_account', 999999999))->assertNotFound();
    }

    public function test_detail(): void
    {
        [$a, $b] = [$this->account(), $this->account()];
        $prev = $this->transfer($a, $b, 100, '2099-01-01 10:00:00');
        $t = $this->transfer($b, $a, 100, '2099-01-01 10:00:00', ['previous_transfer_id' => $prev]);
        $bt = (int) DB::table('bank_transfers')->insertGetId(['transfer_id' => $prev]);

        $res = $this->as()->get(route('transfers.show', $t))->assertOk()->assertViewIs('transfers.show');
        $tr = $res->viewData('transfer');
        $this->assertSame([$prev, $bt], [(int) $tr->previousTransfer->id, (int) $tr->previousTransfer->bankTransfer->id]);
        $this->assertSame([$t], $this->as()->get(route('transfers.show', $prev))->viewData('transfer')->dependentTransfers->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->as()->get(route('transfers.show', 999999999))->assertNotFound();
    }

    public function test_prava(): void
    {
        $a = $this->account();
        $t = $this->transfer($a, $a, 1, '2099-01-01 10:00:00');
        $this->acl(['view_all/Accounts_Controller/transfers']);
        foreach ([route('transfers.index'), route('transfers.by_account', $a), route('transfers.show', $t)] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
    }
}
