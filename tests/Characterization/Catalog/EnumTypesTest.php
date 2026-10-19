<?php

namespace Tests\Characterization\Catalog;

use App\Models\EnumType;
use Illuminate\Support\Facades\DB;

/**
 * Charakterizace číselníku typů (EnumTypeController): skupiny s hodnotami
 * podle abecedy, založení, úprava a smazání (systémové jen pro čtení), ACL.
 */
class EnumTypesTest extends CatalogTestCase
{
    private function type(int $group, array $over = []): int
    {
        return (int) DB::table('enum_types')->insertGetId($over + ['type_id' => $group, 'value' => "Char {$this->tag}", 'read_only' => 0, 'deprecated' => 0]);
    }

    public function test_seznam(): void
    {
        $b = $this->type(EnumType::CONTACT_GROUP_ID, ['value' => "zz {$this->tag}"]);
        $a = $this->type(EnumType::CONTACT_GROUP_ID, ['value' => "aa {$this->tag}"]);
        $groups = $this->as()->get(route('enum-types.index'))->assertOk()->assertViewIs('enum_types.index')->viewData('groups');
        $this->assertSame(DB::table('enum_type_names')->orderBy('type_name')->pluck('id')->map(fn ($i) => (int) $i)->all(), $groups->pluck('id')->map(fn ($i) => (int) $i)->all());
        $ids = $groups->firstWhere('id', EnumType::CONTACT_GROUP_ID)->enumTypes->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertLessThan(array_search($b, $ids, true), array_search($a, $ids, true), 'hodnoty podle abecedy');
    }

    public function test_zalozeni_uprava_smazani(): void
    {
        $this->as()->get(route('enum-types.create'))->assertOk()->assertViewHas('typeNames');
        $this->as()->post(route('enum-types.store'), ['type_id' => EnumType::CONTACT_GROUP_ID, 'value' => "Char {$this->tag}"])
            ->assertRedirect(route('enum-types.index'))->assertSessionHas('success', 'Typ byl přidán.');
        $id = (int) DB::table('enum_types')->where('value', "Char {$this->tag}")->value('id');
        $this->assertSame([EnumType::CONTACT_GROUP_ID, 0, 0], array_map('intval', array_values((array) DB::table('enum_types')->where('id', $id)->first(['type_id', 'read_only', 'deprecated']))));
        $this->as()->post(route('enum-types.store'), ['type_id' => 999999, 'value' => ''])->assertSessionHasErrors(['type_id', 'value']);

        $this->as()->get(route('enum-types.edit', $id))->assertOk()->assertViewHas('enumType', fn ($t) => (int) $t->id === $id);
        $this->as()->put(route('enum-types.update', $id), ['type_id' => EnumType::CONTACT_GROUP_ID, 'value' => "Upr {$this->tag}"])
            ->assertRedirect(route('enum-types.index'))->assertSessionHas('success', 'Typ byl upraven.');
        $this->assertSame("Upr {$this->tag}", DB::table('enum_types')->where('id', $id)->value('value'));
        $this->as()->put(route('enum-types.update', 999999999), [])->assertNotFound();

        $this->as()->delete(route('enum-types.destroy', $id))->assertRedirect(route('enum-types.index'))->assertSessionHas('success', 'Typ byl smazán.');
        $this->assertFalse(DB::table('enum_types')->where('id', $id)->exists());
        $this->as()->delete(route('enum-types.destroy', $id))->assertNotFound();
    }

    public function test_systemove_jen_pro_cteni(): void
    {
        $id = $this->type(EnumType::CONTACT_GROUP_ID, ['read_only' => 1]);
        $this->as()->from(route('enum-types.index'))->put(route('enum-types.update', $id), ['type_id' => EnumType::CONTACT_GROUP_ID, 'value' => 'x'])
            ->assertRedirect(route('enum-types.index'))->assertSessionHas('error', 'Tento typ je systémový a nelze ho upravit.');
        $this->as()->from(route('enum-types.index'))->delete(route('enum-types.destroy', $id))->assertSessionHas('error', 'Tento typ je systémový a nelze ho smazat.');
        $this->assertSame("Char {$this->tag}", DB::table('enum_types')->where('id', $id)->value('value'));
    }

    /** Používaný typ nejde smazat ani přesunout do jiné skupiny (dřív osiřely odkazy, šablony zařízení se smazaly kaskádou). */
    public function test_pouzivany_typ(): void
    {
        $id = $this->type(EnumType::DEVICE_GROUP_ID);
        $dev = (int) DB::table('devices')->insertGetId(['name' => 'char-enum', 'type' => $id, 'user_id' => $this->adminId]);
        DB::table('device_templates')->insert(['name' => 'char', 'enum_type_id' => $id, 'default' => 0, 'values' => '{}']);

        $this->as()->from(route('enum-types.index'))->delete(route('enum-types.destroy', $id))
            ->assertRedirect(route('enum-types.index'))->assertSessionHas('error', 'Typ používá 2 záznamů — nelze ho smazat.');
        $this->assertTrue(DB::table('device_templates')->where('enum_type_id', $id)->exists());
        $this->as()->from(route('enum-types.edit', $id))->put(route('enum-types.update', $id), ['type_id' => EnumType::CONTACT_GROUP_ID, 'value' => 'x'])
            ->assertSessionHas('error', 'Typ používá 2 záznamů — nelze ho přesunout do jiné skupiny.');
        $this->as()->put(route('enum-types.update', $id), ['type_id' => EnumType::DEVICE_GROUP_ID, 'value' => "Přejm {$this->tag}"])->assertSessionHas('success');
        $this->assertSame([EnumType::DEVICE_GROUP_ID, "Přejm {$this->tag}"], [(int) DB::table('enum_types')->where('id', $id)->value('type_id'), DB::table('enum_types')->where('id', $id)->value('value')]);

        DB::table('device_templates')->where('enum_type_id', $id)->delete();
        DB::table('devices')->where('id', $dev)->delete();
        $this->as()->delete(route('enum-types.destroy', $id))->assertSessionHas('success', 'Typ byl smazán.');
    }

    public function test_prava(): void
    {
        $id = $this->type(EnumType::CONTACT_GROUP_ID);
        $this->acl(['view_all/Enum_types_Controller/enum_types', 'new_all/Enum_types_Controller/enum_types', 'edit_all/Enum_types_Controller/enum_types', 'delete_all/Enum_types_Controller/enum_types']);
        foreach ([route('enum-types.index'), route('enum-types.create'), route('enum-types.edit', $id)] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        $this->as()->post(route('enum-types.store'), [])->assertForbidden();
        $this->as()->put(route('enum-types.update', $id), [])->assertForbidden();
        $this->as()->delete(route('enum-types.destroy', $id))->assertForbidden();
        $this->assertTrue(DB::table('enum_types')->where('id', $id)->exists());
    }
}
