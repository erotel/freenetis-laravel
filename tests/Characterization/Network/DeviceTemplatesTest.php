<?php

namespace Tests\Characterization\Network;

use App\Domains\Acl\AclService;
use App\Models\EnumType;
use App\Models\Iface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace šablon zařízení (DeviceTemplateController) před přesunem do
 * domény Network: seznam, detail, založení / úprava (definice rozhraní podle
 * typu: wifi min/max, ostatní počet; IP/MAC podle typu; jména položek),
 * jediná výchozí šablona na typ zařízení, smazání, export JSON, ACL.
 */
class DeviceTemplatesTest extends DatabaseTestCase
{
    private int $adminId;
    private int $deviceType;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->deviceType = (int) DB::table('enum_types')->where('type_id', EnumType::DEVICE_GROUP_ID)->value('id');
        $this->tag = 'Dt' . substr(md5(uniqid('', true)), 0, 8);
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

    private function data(array $over = []): array
    {
        return $over + ['name' => "Char {$this->tag}", 'enum_type_id' => $this->deviceType, 'default' => '1',
            'iface_' . Iface::WIRELESS . '_min_count' => 2, 'iface_' . Iface::WIRELESS . '_max_count' => 1,
            'iface_' . Iface::WIRELESS . '_names' => ['wlan0', 'wlan1'],
            'iface_' . Iface::ETHERNET . '_count' => 1, 'iface_' . Iface::ETHERNET . '_names' => ['ether1']];
    }

    public function test_zalozeni_uprava_vychozi(): void
    {
        $this->as()->get(route('device_templates.create'))->assertOk()->assertViewHas('deviceTypes', fn ($t) => isset($t[$this->deviceType]));
        $old = (int) DB::table('device_templates')->insertGetId(['name' => 'stara', 'enum_type_id' => $this->deviceType, 'default' => 1, 'values' => '{}']);

        $res = $this->as()->post(route('device_templates.store'), $this->data());
        $id = (int) DB::table('device_templates')->where('name', "Char {$this->tag}")->value('id');
        $res->assertRedirect(route('device_templates.show', $id))->assertSessionHas('success', 'Šablona zařízení byla úspěšně přidána.');
        $values = json_decode(DB::table('device_templates')->where('id', $id)->value('values'), true);
        $this->assertSame(['type' => Iface::WIRELESS, 'min_count' => 2, 'max_count' => 2, 'has_ip' => true, 'has_mac' => true, 'has_link' => true,
            'items' => [['name' => 'wlan0'], ['name' => 'wlan1']]], $values[Iface::WIRELESS], 'max ≥ min');
        $this->assertSame(['type' => Iface::ETHERNET, 'count' => 1, 'has_ip' => true, 'has_mac' => true, 'has_link' => true, 'items' => [['name' => 'ether1']]], $values[Iface::ETHERNET]);
        $this->assertSame(['type' => Iface::VLAN_IFACE, 'count' => 0, 'has_ip' => false, 'has_mac' => false, 'has_link' => true, 'items' => []], $values[Iface::VLAN_IFACE]);
        $this->assertSame(0, (int) DB::table('device_templates')->where('id', $old)->value('default'), 'jediná výchozí na typ');

        $show = $this->as()->get(route('device_templates.show', $id))->assertOk()->assertViewIs('device_templates.show');
        $this->assertNotEmpty($show->viewData('ifaceDefs'));
        $this->as()->get(route('device_templates.edit', $id))->assertOk();
        $this->as()->put(route('device_templates.update', $id), ['name' => "Upr {$this->tag}", 'enum_type_id' => $this->deviceType])
            ->assertRedirect(route('device_templates.show', $id))->assertSessionHas('success', 'Šablona zařízení byla úspěšně upravena.');
        $r = DB::table('device_templates')->where('id', $id)->first();
        $this->assertSame(["Upr {$this->tag}", 0], [$r->name, (int) $r->default]);
        $this->as()->put(route('device_templates.update', 999999999), $this->data())->assertNotFound();
        $this->as()->post(route('device_templates.store'), ['name' => '', 'enum_type_id' => 999999999])->assertSessionHasErrors(['name', 'enum_type_id']);

        // počty rozhraní 0–64 a typ zařízení jen z číselníku zařízení
        $other = (int) DB::table('enum_types')->where('type_id', '<>', EnumType::DEVICE_GROUP_ID)->value('id');
        $this->as()->post(route('device_templates.store'), $this->data(['enum_type_id' => $other, 'iface_' . Iface::ETHERNET . '_count' => 100000,
            'iface_' . Iface::WIRELESS . '_min_count' => -1]))->assertSessionHasErrors(['enum_type_id' => 'Vyberte typ zařízení.',
            'iface_' . Iface::ETHERNET . '_count', 'iface_' . Iface::WIRELESS . '_min_count']);
    }

    public function test_seznam_export_smazani(): void
    {
        $id = (int) DB::table('device_templates')->insertGetId(['name' => "Char {$this->tag}", 'enum_type_id' => $this->deviceType, 'default' => 0, 'values' => '{"2":{"type":2}}']);
        $index = $this->as()->get(route('device_templates.index'))->assertOk();
        $this->assertSame(DB::table('device_templates')->orderBy('name')->pluck('id')->map(fn ($i) => (int) $i)->all(), $index->viewData('templates')->pluck('id')->all());

        $export = $this->as()->get(route('device_templates.export'))->assertOk();
        $this->assertStringContainsString('device_templates.json', $export->headers->get('Content-Disposition'));
        $this->assertContains(['name' => "Char {$this->tag}", 'enum_type_id' => $this->deviceType, 'values' => ['2' => ['type' => 2]], 'default' => false], $export->json());

        $this->as()->delete(route('device_templates.destroy', $id))->assertRedirect(route('device_templates.index'))->assertSessionHas('success', 'Šablona zařízení byla smazána.');
        $this->assertFalse(DB::table('device_templates')->where('id', $id)->exists());
        $this->as()->delete(route('device_templates.destroy', 999999999))->assertNotFound();
    }

    public function test_prava(): void
    {
        $id = (int) DB::table('device_templates')->insertGetId(['name' => "Char {$this->tag}", 'enum_type_id' => $this->deviceType, 'default' => 0, 'values' => '{}']);
        $this->acl(['view_all/Device_templates_Controller/device_template', 'new_all/Device_templates_Controller/device_template',
            'edit_all/Device_templates_Controller/device_template', 'delete_all/Device_templates_Controller/device_template']);
        foreach ([route('device_templates.index'), route('device_templates.show', $id), route('device_templates.create'), route('device_templates.edit', $id), route('device_templates.export')] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        $this->as()->post(route('device_templates.store'), $this->data())->assertForbidden();
        $this->as()->put(route('device_templates.update', $id), $this->data())->assertForbidden();
        $this->as()->delete(route('device_templates.destroy', $id))->assertForbidden();
    }
}
