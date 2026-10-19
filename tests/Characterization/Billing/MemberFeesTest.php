<?php

namespace Tests\Characterization\Billing;

use App\Models\Fee;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\Characterization\Catalog\CatalogTestCase;

/**
 * Charakterizace tarifů člena (MemberFeeController) před přesunem do domény
 * Billing: přehled podle typu (+ virtuální výchozí tarif z nastavení),
 * přiřazení (výchozí konec 9999-12-31, priorita 1), dodatečná služba u
 * podepsané smlouvy jen dodatkem, úprava, odebrání, ACL.
 */
class MemberFeesTest extends CatalogTestCase
{
    private function member(int $type = 2): int
    {
        return (int) DB::table('members')->insertGetId(['name' => "Char {$this->tag}", 'type' => $type, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
    }

    private function fee(int $type, array $over = []): int
    {
        return (int) DB::table('fees')->insertGetId($over + ['readonly' => 0, 'archived' => 0, 'fee' => 100, 'from' => '2020-01-01', 'to' => '9999-12-31',
            'type_id' => $type, 'name' => "Fee {$this->tag} {$type}", 'special_type_id' => null]);
    }

    private function rows(int $m): array
    {
        return DB::table('members_fees')->where('member_id', $m)->orderBy('id')->get(['fee_id', 'activation_date', 'deactivation_date', 'priority', 'comment'])
            ->map(fn ($r) => [(int) $r->fee_id, $r->activation_date, $r->deactivation_date, (int) $r->priority, $r->comment])->all();
    }

    public function test_prehled(): void
    {
        $m = $this->member(2);
        $regular = $this->fee(Fee::TYPE_REGULAR);
        $service = $this->fee(Fee::TYPE_ADDITIONAL_SERVICE);
        DB::table('members_fees')->insert([
            ['fee_id' => $regular, 'member_id' => $m, 'activation_date' => '2020-01-01', 'deactivation_date' => '2024-12-31', 'priority' => 1, 'comment' => ''],
            ['fee_id' => $regular, 'member_id' => $m, 'activation_date' => '2025-01-01', 'deactivation_date' => '9999-12-31', 'priority' => 1, 'comment' => ''],
            ['fee_id' => $service, 'member_id' => $m, 'activation_date' => '2025-01-01', 'deactivation_date' => '9999-12-31', 'priority' => 1, 'comment' => ''],
        ]);
        $default = $this->fee(Fee::TYPE_REGULAR, ['from' => '2021-01-01']);
        Setting::set('default_fee_member_type_2', (string) $default);

        $res = $this->as()->get(route('members_fees.by_member', $m))->assertOk()->assertViewIs('members_fees.show_by_member');
        $fees = $res->viewData('memberFees');
        $this->assertSame([Fee::TYPE_REGULAR, Fee::TYPE_ADDITIONAL_SERVICE], $fees->keys()->all());
        $this->assertSame(['2025-01-01', '2020-01-01'], $fees[Fee::TYPE_REGULAR]->map(fn ($mf) => $mf->activation_date->toDateString())->all(), 'nejnovější první');
        $virtual = $res->viewData('defaultFees')[Fee::TYPE_REGULAR]->first();
        $this->assertSame([$default, '2021-01-01', false], [(int) $virtual->fee_id, $virtual->activation_date->toDateString(), $virtual->exists]);
        $this->assertSame(Fee::typeLabels(), $res->viewData('typeLabels'));
        $this->assertTrue($res->viewData('canAdd') && $res->viewData('canEdit') && $res->viewData('canDelete'));

        Setting::set('default_fee_member_type_2', '0');
        $this->assertTrue($this->as()->get(route('members_fees.by_member', $m))->viewData('defaultFees')->isEmpty());
        $this->as()->get(route('members_fees.by_member', 999999999))->assertNotFound();
    }

    public function test_prirazeni_uprava_odebrani(): void
    {
        $m = $this->member();
        $regular = $this->fee(Fee::TYPE_REGULAR);
        $archived = $this->fee(Fee::TYPE_REGULAR, ['archived' => 1]);

        $create = $this->as()->get(route('members_fees.create', $m))->assertOk();
        $ids = $create->viewData('fees')->pluck('id')->map(fn ($i) => (int) $i);
        $this->assertTrue($ids->contains($regular));
        $this->assertFalse($ids->contains($archived), 'jen přiřaditelné');
        $this->as()->get(route('members_fees.create', 999999999))->assertNotFound();

        $this->as()->post(route('members_fees.store', $m), ['fee_id' => $regular, 'activation_date' => '2026-01-01'])
            ->assertRedirect(route('members_fees.by_member', $m))->assertSessionHas('success', 'Tarif byl úspěšně přiřazen.');
        $this->assertSame([[$regular, '2026-01-01', '9999-12-31', 1, '']], $this->rows($m));
        $this->as()->post(route('members_fees.store', $m), ['fee_id' => 999999999, 'activation_date' => '2026-02-01', 'deactivation_date' => '2026-01-01'])
            ->assertSessionHasErrors(['fee_id', 'deactivation_date']);
        $this->as()->post(route('members_fees.store', 999999999), ['fee_id' => $regular, 'activation_date' => '2026-01-01'])->assertNotFound();

        $id = (int) DB::table('members_fees')->where('member_id', $m)->value('id');
        $edit = $this->as()->get(route('members_fees.edit', $id))->assertOk();
        $this->assertSame($m, (int) $edit->viewData('memberFee')->member->id);
        $this->as()->put(route('members_fees.update', $id), ['fee_id' => $regular, 'activation_date' => '2026-03-01', 'deactivation_date' => '2026-12-31', 'comment' => 'pozn'])
            ->assertRedirect(route('members_fees.by_member', $m))->assertSessionHas('success', 'Tarif byl úspěšně upraven.');
        $this->assertSame([[$regular, '2026-03-01', '2026-12-31', 1, 'pozn']], $this->rows($m));
        $this->as()->put(route('members_fees.update', 999999999), ['fee_id' => $regular, 'activation_date' => '2026-03-01'])->assertNotFound();

        $this->as()->delete(route('members_fees.destroy', $id))->assertRedirect(route('members_fees.by_member', $m))->assertSessionHas('success', 'Tarif byl odebrán.');
        $this->assertSame([], $this->rows($m));
        $this->as()->delete(route('members_fees.destroy', $id))->assertNotFound();
    }

    public function test_dodatecna_sluzba_u_podepsane_smlouvy(): void
    {
        $m = $this->member();
        $service = $this->fee(Fee::TYPE_ADDITIONAL_SERVICE);
        $this->as()->post(route('members_fees.store', $m), ['fee_id' => $service, 'activation_date' => '2026-01-01'])->assertSessionHas('success');

        $c = $this->member();
        DB::connection('contracts')->table('contracts')->insert(['member_id' => $c, 'contract_no' => "SML-{$this->tag}", 'status' => 'signed',
            'phone' => '777000000', 'created_at' => '2026-01-01 00:00:00']);
        $this->as()->post(route('members_fees.store', $c), ['fee_id' => $service, 'activation_date' => '2026-01-01'])
            ->assertRedirect(route('contracts.show', $c))
            ->assertSessionHas('error', 'Dodatečnou službu u člena s podepsanou smlouvou přidej dodatkem ke smlouvě (přiřadí se po podpisu), ne přímo v poplatcích.');
        $this->assertSame([], $this->rows($c));
        $this->as()->post(route('members_fees.store', $c), ['fee_id' => $this->fee(Fee::TYPE_REGULAR), 'activation_date' => '2026-01-01'])->assertSessionHas('success');
    }

    public function test_prava(): void
    {
        $m = $this->member();
        $id = (int) DB::table('members_fees')->insertGetId(['fee_id' => $this->fee(Fee::TYPE_REGULAR), 'member_id' => $m, 'activation_date' => '2020-01-01',
            'deactivation_date' => '9999-12-31', 'priority' => 1, 'comment' => '']);
        $this->acl(['view_all/Members_Controller/fees', 'new_all/Members_Controller/fees', 'edit_all/Members_Controller/fees', 'delete_all/Members_Controller/fees']);
        foreach ([route('members_fees.by_member', $m), route('members_fees.create', $m), route('members_fees.edit', $id)] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        $this->as()->post(route('members_fees.store', $m), [])->assertForbidden();
        $this->as()->put(route('members_fees.update', $id), [])->assertForbidden();
        $this->as()->delete(route('members_fees.destroy', $id))->assertForbidden();
        $this->assertTrue(DB::table('members_fees')->where('id', $id)->exists());
    }
}
