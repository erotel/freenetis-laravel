<?php

namespace Tests\Characterization\Network;

use Illuminate\Support\Facades\DB;
use Tests\Characterization\Catalog\CatalogTestCase;

/**
 * Charakterizace VLAN (VlanController) před přesunem do domény Network:
 * seznam (hledání, řazení, počty rozhraní), detail s rozhraními, založení /
 * úprava (tag 1–4094 jedinečný), smazání (ne výchozí, ne s rozhraními), ACL.
 */
class VlansTest extends CatalogTestCase
{
    private function freeTag(): int
    {
        do {
            $tag = random_int(2000, 4094);
        } while (DB::table('vlans')->where('tag_802_1q', $tag)->exists());
        return $tag;
    }

    private function vlan(array $over = []): int
    {
        return (int) DB::table('vlans')->insertGetId($over + ['name' => "Char {$this->tag}", 'tag_802_1q' => $this->freeTag(), 'comment' => 'c']);
    }

    public function test_seznam_a_detail(): void
    {
        $a = $this->vlan(['comment' => "hledej {$this->tag}"]);
        $b = $this->vlan(['name' => "Char {$this->tag} b"]);
        $dev = (int) DB::table('devices')->insertGetId(['name' => 'char-vlan', 'type' => 1, 'user_id' => $this->adminId]);
        $iface = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'e', 'device_id' => $dev]);
        DB::table('ifaces_vlans')->insert(['iface_id' => $iface, 'vlan_id' => $a, 'tagged' => 1, 'port_vlan' => 0]);

        $res = $this->as()->get(route('vlans.index', ['q' => $this->tag, 'sort' => 'name', 'dir' => 'desc', 'record_per_page' => 100]))->assertOk();
        $items = collect($res->viewData('vlans')->items());
        $this->assertSame([[$b, 0], [$a, 1]], $items->map(fn ($v) => [(int) $v->id, (int) $v->ifaces_count])->all());
        $this->assertSame(['name', 'desc', 100, $this->tag], [$res->viewData('sort'), $res->viewData('dir'), $res->viewData('perPage'), $res->viewData('q')]);
        $tagB = (int) DB::table('vlans')->where('id', $b)->value('tag_802_1q');
        $this->assertSame([$b], collect($this->as()->get(route('vlans.index', ['q' => (string) $tagB]))->viewData('vlans')->items())->pluck('id')->map(fn ($i) => (int) $i)->all());
        $def = $this->as()->get(route('vlans.index', ['sort' => 'x', 'record_per_page' => 7]));
        $this->assertSame(['tag_802_1q', 'asc', 50], [$def->viewData('sort'), $def->viewData('dir'), $def->viewData('perPage')]);

        $show = $this->as()->get(route('vlans.show', $a))->assertOk()->assertViewIs('vlans.show');
        $this->assertSame([$iface], $show->viewData('vlan')->ifaces->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame('char-vlan', $show->viewData('vlan')->ifaces->first()->device->name);
        $this->assertTrue($show->viewData('canViewDevices'));
        $this->as()->get(route('vlans.show', 999999999))->assertNotFound();
    }

    public function test_zalozeni_uprava_smazani(): void
    {
        $tag = $this->freeTag();
        $this->as()->get(route('vlans.create'))->assertOk();
        $this->as()->post(route('vlans.store'), ['name' => "Char {$this->tag}", 'tag_802_1q' => $tag, 'comment' => 'k']);
        $id = (int) DB::table('vlans')->where('tag_802_1q', $tag)->value('id');
        $this->assertSame(["Char {$this->tag}", 'k'], [DB::table('vlans')->where('id', $id)->value('name'), DB::table('vlans')->where('id', $id)->value('comment')]);
        $this->as()->post(route('vlans.store'), ['tag_802_1q' => $tag])->assertSessionHasErrors(['tag_802_1q' => 'Tento 802.1Q tag je již použit.']);
        $this->as()->post(route('vlans.store'), ['tag_802_1q' => 4095])->assertSessionHasErrors(['tag_802_1q' => '802.1Q tag musí být v rozsahu 1–4094.']);
        $this->as()->post(route('vlans.store'), [])->assertSessionHasErrors(['tag_802_1q' => 'Pole 802.1Q tag je povinné.']);
        $this->as()->post(route('vlans.store'), ['tag_802_1q' => 'x'])->assertSessionHasErrors(['tag_802_1q' => '802.1Q tag musí být celé číslo.']);

        $this->as()->get(route('vlans.edit', $id))->assertOk()->assertViewHas('vlan', fn ($v) => (int) $v->id === $id);
        $this->as()->put(route('vlans.update', $id), ['name' => 'Upr', 'tag_802_1q' => $tag])
            ->assertRedirect(route('vlans.show', $id))->assertSessionHas('success', 'VLAN byla úspěšně upravena.');
        // chybějící pole se nemění (validate vrací jen poslaná)
        $this->assertSame(['Upr', 'k'], [DB::table('vlans')->where('id', $id)->value('name'), DB::table('vlans')->where('id', $id)->value('comment')]);
        $this->as()->get(route('vlans.edit', 999999999))->assertNotFound();
        $this->as()->put(route('vlans.update', 999999999), ['tag_802_1q' => $this->freeTag()])->assertNotFound();

        $iface = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'e', 'device_id' => (int) DB::table('devices')->insertGetId(['name' => 'v', 'type' => 1, 'user_id' => $this->adminId])]);
        DB::table('ifaces_vlans')->insert(['iface_id' => $iface, 'vlan_id' => $id, 'tagged' => 1, 'port_vlan' => 0]);
        $this->as()->from(route('vlans.show', $id))->delete(route('vlans.destroy', $id))
            ->assertRedirect(route('vlans.show', $id))->assertSessionHas('error', 'VLAN nelze smazat, má přiřazená rozhraní.');
        DB::table('ifaces_vlans')->where('vlan_id', $id)->delete();
        $this->as()->delete(route('vlans.destroy', $id))->assertRedirect(route('vlans.index'))->assertSessionHas('success', 'VLAN byla úspěšně smazána.');
        $this->assertFalse(DB::table('vlans')->where('id', $id)->exists());
        $this->as()->delete(route('vlans.destroy', $id))->assertNotFound();

        $default = (int) (DB::table('vlans')->where('tag_802_1q', 1)->value('id') ?: $this->vlan(['tag_802_1q' => 1]));
        $this->as()->from(route('vlans.index'))->delete(route('vlans.destroy', $default))->assertSessionHas('error', 'Výchozí VLAN nelze smazat.');
        $this->assertTrue(DB::table('vlans')->where('id', $default)->exists());
    }

    public function test_prava(): void
    {
        $id = $this->vlan();
        $this->acl(['view_all/Vlans_Controller/vlan', 'new_all/Vlans_Controller/vlan', 'edit_all/Vlans_Controller/vlan', 'delete_all/Vlans_Controller/vlan']);
        foreach ([route('vlans.index'), route('vlans.show', $id), route('vlans.create'), route('vlans.edit', $id)] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        $this->as()->post(route('vlans.store'), [])->assertForbidden();
        $this->as()->put(route('vlans.update', $id), [])->assertForbidden();
        $this->as()->delete(route('vlans.destroy', $id))->assertForbidden();
        $this->assertTrue(DB::table('vlans')->where('id', $id)->exists());
    }
}
