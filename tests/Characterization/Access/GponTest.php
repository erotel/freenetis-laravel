<?php

namespace Tests\Characterization\Access;

use App\Models\Setting;
use App\Services\GponService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Characterization\Catalog\CatalogTestCase;

/**
 * Charakterizace GPON (GponController) před přesunem do domény Access:
 * seznam ONT (filtr stavu, počty), detail (SNMP detaily jen u
 * registrované), přiřazení zákazníka, sken / registrace / odebrání přes
 * OLT (GponService mock), smazání jen odebrané, mapa, ACL.
 */
class GponTest extends CatalogTestCase
{
    private $gpon;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('gpon_enabled', '1');
        $this->gpon = $this->mock(GponService::class);
    }

    private function ont(string $status, array $over = []): int
    {
        return (int) DB::table('onts')->insertGetId($over + ['serial' => 'CHAR' . strtoupper(substr(md5(uniqid()), 0, 8)), 'gpon_port' => '0/1/0', 'port_num' => 0,
            'port_index' => 4194304000, 'ont_id' => 1, 'service_port' => 1, 'vlan' => 100, 'reg_status' => $status, 'olt_ip' => '10.0.0.1',
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_seznam_detail_mapa(): void
    {
        $new = $this->ont('new');
        $reg = $this->ont('registered', ['gps_lat' => 49.47, 'gps_lng' => 17.11, 'user_name' => DB::table('users')->where('id', $this->adminId)->value('login')]);

        $res = $this->as()->get(route('gpon.index', ['status' => 'new']))->assertOk()->assertViewIs('gpon.index');
        $ids = collect($res->viewData('onts')->items())->pluck('id')->map(fn ($i) => (int) $i);
        $this->assertTrue($ids->contains($new) && !$ids->contains($reg));
        $this->assertSame(DB::table('onts')->where('reg_status', 'registered')->count(), $res->viewData('counts')['registered']);
        $this->assertTrue(collect($this->as()->get(route('gpon.index', ['status' => 'xx']))->viewData('onts')->items())->pluck('id')->map(fn ($i) => (int) $i)->contains($reg), 'neznámý stav = vše');

        $details = ['rx_power' => '-20.5', 'tx_power' => '2.1', 'voltage' => '3.3', 'current' => '10', 'temperature' => '40', 'distance' => '900',
            'eth_status' => 'UP', 'eth_duplex' => 'full', 'eth_speed' => '1000', 'online' => true, 'status' => 'Online', 'firmware' => 'V5', 'model' => 'HG8010'];
        $this->gpon->shouldReceive('getOntDetails')->once()->andReturn($details);
        $show = $this->as()->get(route('gpon.show', $reg))->assertOk();
        $this->assertSame($details, $show->viewData('details'));
        $this->assertSame($this->adminId, (int) $show->viewData('ont')->addedBy->id);
        $this->assertNull($this->as()->get(route('gpon.show', $new))->viewData('details'), 'neregistrovaná bez SNMP');
        $this->as()->get(route('gpon.show', 999999999))->assertNotFound();

        $this->gpon->shouldReceive('getBatchOnlineStatus')->once()->andReturn(['4194304000.1' => true]);
        $map = $this->as()->get(route('gpon.map'))->assertOk();
        $this->assertTrue($map->viewData('onts')->pluck('id')->map(fn ($i) => (int) $i)->contains($reg));
        $this->assertSame(['4194304000.1' => true], $map->viewData('onlineStatus'));
    }

    public function test_detail_pri_chybe_olt(): void
    {
        $reg = $this->ont('registered');
        $this->gpon->shouldReceive('getOntDetails')->andThrow(new \RuntimeException('timeout'));
        $this->assertNull($this->as()->get(route('gpon.show', $reg))->assertOk()->viewData('details'));
    }

    public function test_akce(): void
    {
        $id = $this->ont('new');
        $m = (int) DB::table('members')->where('id', '>', 1)->value('id');

        $this->as()->post(route('gpon.update-member', $id), ['member_id' => $m])->assertRedirect(route('gpon.show', $id))->assertSessionHas('success', 'Zákazník upraven.');
        $this->assertSame($m, (int) DB::table('onts')->where('id', $id)->value('member_id'));
        $this->as()->post(route('gpon.update-member', $id), ['member_id' => ''])->assertSessionHas('success');
        $this->assertNull(DB::table('onts')->where('id', $id)->value('member_id'));
        $this->as()->post(route('gpon.update-member', $id), ['member_id' => 999999999])->assertSessionHasErrors('member_id');

        $this->gpon->shouldReceive('scanNewOnts')->once()->andReturn(3);
        $this->as()->from(route('gpon.index'))->post(route('gpon.scan'))->assertRedirect(route('gpon.index'))->assertSessionHas('success', 'Skenování dokončeno. Nalezeno 3 nových ONT.');

        $this->gpon->shouldReceive('registerOntById')->once()->with($id, 'č.p. 5', 'jan')->andReturnUsing(fn () => DB::table('onts')->where('id', $id)->update(['reg_status' => 'registered', 'user_name' => 'jan']));
        $this->as()->post(route('gpon.register', $id), ['house_no' => 'č.p. 5', 'user_name' => 'jan', 'member_id' => $m])
            ->assertRedirect(route('gpon.show', $id))->assertSessionHas('success', 'ONT byla úspěšně zaregistrována.');
        $this->assertSame([$m, null], [(int) DB::table('onts')->where('id', $id)->value('member_id'), DB::table('onts')->where('id', $id)->value('user_name')]);

        $this->as()->delete(route('gpon.destroy', $id))->assertRedirect(route('gpon.show', $id))->assertSessionHas('error', 'ONT lze smazat pouze ve stavu Odebrána.');

        $this->gpon->shouldReceive('removeOntById')->once()->with($id)->andReturnUsing(fn () => DB::table('onts')->where('id', $id)->update(['reg_status' => 'removed']));
        $this->as()->post(route('gpon.remove', $id))->assertRedirect(route('gpon.index'))->assertSessionHas('success', 'ONT byla odebrána.');
        $this->as()->delete(route('gpon.destroy', $id))->assertRedirect(route('gpon.index'))->assertSessionHas('success', 'ONT byla smazána z evidence.');
        $this->assertFalse(DB::table('onts')->where('id', $id)->exists());
        $this->as()->delete(route('gpon.destroy', $id))->assertNotFound();
    }

    public function test_chyby_olt(): void
    {
        $id = $this->ont('new');
        $this->gpon->shouldReceive('scanNewOnts')->andThrow(new \RuntimeException('SNMP timeout'));
        $this->gpon->shouldReceive('registerOntById')->andThrow(new \RuntimeException('obsazeno'));
        $this->gpon->shouldReceive('removeOntById')->andThrow(new \RuntimeException('nenalezena'));
        $this->as()->from(route('gpon.index'))->post(route('gpon.scan'))->assertSessionHas('error', 'Chyba při skenování: SNMP timeout');
        $this->as()->post(route('gpon.register', $id), [])->assertRedirect(route('gpon.show', $id))->assertSessionHas('error', 'Chyba při registraci: obsazeno');
        $this->as()->post(route('gpon.remove', $id))->assertRedirect(route('gpon.show', $id))->assertSessionHas('error', 'Chyba při odebrání: nenalezena');
    }

    public function test_prava_a_vypnuty_modul(): void
    {
        $id = $this->ont('removed');
        $this->acl(['view_all/Gpon_Controller/gpon', 'edit_all/Gpon_Controller/gpon', 'delete_all/Gpon_Controller/gpon']);
        foreach ([route('gpon.index'), route('gpon.show', $id), route('gpon.map')] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        foreach ([route('gpon.scan'), route('gpon.register', $id), route('gpon.remove', $id), route('gpon.update-member', $id)] as $url) {
            $this->as()->post($url)->assertForbidden();
        }
        $this->as()->delete(route('gpon.destroy', $id))->assertForbidden();
        $this->assertTrue(DB::table('onts')->where('id', $id)->exists());

        $this->acl();
        Setting::set('gpon_enabled', '0');
        $this->assertNotSame(200, $this->as()->get(route('gpon.index'))->status());
    }
}
