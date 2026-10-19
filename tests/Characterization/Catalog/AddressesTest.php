<?php

namespace Tests\Characterization\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * Charakterizace obcí a ulic (TownController, StreetController): seznamy
 * (řazení, stránkování), detail (adresní body celkem / prázdné, členové),
 * CRUD s unikátností (obec+čtvrť+PSČ, ulice v obci), mazání jen bez
 * adresních bodů, ACL.
 */
class AddressesTest extends CatalogTestCase
{
    public function test_obce(): void
    {
        $this->as()->post(route('towns.store'), ['town' => "Obec{$this->tag}", 'quarter' => '', 'zip_code' => '79801'])
            ->assertRedirect(route('towns.index'))->assertSessionHas('success', 'Město bylo úspěšně přidáno.');
        $t = DB::table('towns')->where('town', "Obec{$this->tag}")->first();
        $this->as()->post(route('towns.store'), ['town' => "Obec{$this->tag}", 'zip_code' => '79801'])->assertSessionHasErrors(['town' => 'Tato kombinace města, čtvrti a PSČ již existuje.']);
        $this->as()->post(route('towns.store'), ['town' => "Obec{$this->tag}", 'quarter' => 'Část', 'zip_code' => '79801'])->assertSessionHasNoErrors();
        $this->as()->post(route('towns.store'), ['town' => '', 'zip_code' => '7980'])->assertSessionHasErrors(['town', 'zip_code']);

        $ap = (int) DB::table('address_points')->insertGetId(['town_id' => $t->id, 'street_number' => '1', 'country_id' => 1]);
        DB::table('address_points')->insert(['town_id' => $t->id, 'street_number' => '2', 'country_id' => 1]);
        DB::table('members')->insert(['name' => 'Char', 'type' => 2, 'registration' => 1, 'locked' => 0, 'address_point_id' => $ap, 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $show = $this->as()->get(route('towns.show', $t->id))->assertOk();
        $this->assertSame([2, 1, 1], [$show->viewData('apTotal'), $show->viewData('apOrphan'), $show->viewData('memberCount')]);

        $this->as()->put(route('towns.update', $t->id), ['town' => "Obec{$this->tag}", 'quarter' => 'Část', 'zip_code' => '79801'])->assertSessionHasErrors('town');
        $this->as()->put(route('towns.update', $t->id), ['town' => "Nova{$this->tag}", 'zip_code' => '79802'])->assertRedirect(route('towns.index'))->assertSessionHas('success', 'Město bylo úspěšně upraveno.');
        $this->as()->from(route('towns.show', $t->id))->delete(route('towns.destroy', $t->id))->assertSessionHas('error', 'Město nelze smazat, má přiřazené adresní body.');
        $empty = (int) DB::table('towns')->where('town', "Obec{$this->tag}")->where('quarter', 'Část')->value('id');
        $this->as()->delete(route('towns.destroy', $empty))->assertRedirect(route('towns.index'))->assertSessionHas('success', 'Město bylo úspěšně smazáno.');

        $idx = $this->as()->get(route('towns.index', ['sort' => 'zip_code', 'dir' => 'desc', 'record_per_page' => 100]))->assertOk();
        $this->assertSame(['zip_code', 'desc', 100], [$idx->viewData('sort'), $idx->viewData('dir'), $idx->viewData('perPage')]);
        $this->as()->get(route('towns.show', 999999999))->assertNotFound();
    }

    public function test_ulice(): void
    {
        $town = (int) DB::table('towns')->insertGetId(['town' => "Obec{$this->tag}", 'zip_code' => '79801']);
        $this->as()->get(route('streets.create'))->assertOk();
        $this->as()->post(route('streets.store'), ['town_id' => $town, 'street' => "Ulice{$this->tag}"])->assertRedirect(route('streets.index'))->assertSessionHas('success', 'Ulice byla úspěšně přidána.');
        $s = (int) DB::table('streets')->where('street', "Ulice{$this->tag}")->value('id');
        $this->as()->post(route('streets.store'), ['town_id' => $town, 'street' => "Ulice{$this->tag}"])->assertSessionHasErrors(['street' => 'Tato ulice v daném městě již existuje.']);

        DB::table('address_points')->insert(['town_id' => $town, 'street_id' => $s, 'street_number' => '1', 'country_id' => 1]);
        $show = $this->as()->get(route('streets.show', $s))->assertOk();
        $this->assertSame([1, 1, 0], [$show->viewData('apTotal'), $show->viewData('apOrphan'), $show->viewData('memberCount')]);
        $this->as()->put(route('streets.update', $s), ['town_id' => $town, 'street' => "Jina{$this->tag}"])->assertRedirect(route('streets.index'));
        $this->assertSame("Jina{$this->tag}", DB::table('streets')->where('id', $s)->value('street'));
        $this->as()->from(route('streets.show', $s))->delete(route('streets.destroy', $s))->assertSessionHas('error', 'Ulici nelze smazat, má přiřazené adresní body.');
        DB::table('address_points')->where('street_id', $s)->delete();
        $this->as()->delete(route('streets.destroy', $s))->assertRedirect(route('streets.index'))->assertSessionHas('success', 'Ulice byla úspěšně smazána.');
        $this->as()->get(route('streets.index', ['sort' => 'town_id']))->assertOk()->assertViewHas('sort', 'town_id');
    }

    public function test_prava(): void
    {
        $t = (int) DB::table('towns')->value('id');
        $s = (int) DB::table('streets')->value('id');
        $this->acl(['view_all/Address_points_Controller/town', 'new_all/Address_points_Controller/town', 'edit_all/Address_points_Controller/town', 'delete_all/Address_points_Controller/town',
            'view_all/Address_points_Controller/street', 'new_all/Address_points_Controller/street', 'edit_all/Address_points_Controller/street', 'delete_all/Address_points_Controller/street']);
        foreach ([route('towns.show', $t), route('towns.create'), route('towns.edit', $t), route('streets.show', $s), route('streets.create'), route('streets.edit', $s)] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        $this->as()->delete(route('towns.destroy', $t))->assertForbidden();
        $this->as()->delete(route('streets.destroy', $s))->assertForbidden();
    }
}
