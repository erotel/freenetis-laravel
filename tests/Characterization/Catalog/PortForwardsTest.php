<?php

namespace Tests\Characterization\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * Charakterizace port forwardů (PublicPortForwardController): seznam
 * (hledání IP / port v rozsahu / vlastník), založení a úprava (veřejná IP
 * jen z povolených, porty 1–65535, stejně velké rozsahy, kolize na
 * veřejné IP + protokolu), smazání, ACL.
 */
class PortForwardsTest extends CatalogTestCase
{
    private string $pub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pub = '198.51.100.' . random_int(10, 250);
        DB::table('public_ips')->insert(['ip' => $this->pub, 'enabled' => 1, 'note' => 'char']);
    }

    private function data(array $over = []): array
    {
        return $over + ['public_ip' => $this->pub, 'public_port_from' => 8080, 'public_port_to' => 8081, 'private_ip' => '10.245.1.5',
            'private_port_from' => 80, 'private_port_to' => 81, 'protocol' => 'TCP', 'enabled' => '1'];
    }

    public function test_crud_a_validace(): void
    {
        $this->as()->get(route('public-port-forwards.create'))->assertOk()->assertViewHas('publicIps', fn ($ips) => in_array($this->pub, $ips, true));
        $this->as()->post(route('public-port-forwards.store'), $this->data())->assertRedirect(route('public-port-forwards.index'))->assertSessionHas('success', 'Port forward byl uložen.');
        $row = DB::table('public_port_forwards')->where('public_ip', $this->pub)->first();
        $this->assertSame([8080, 8081, 'tcp', 1, (int) DB::table('users')->where('id', $this->adminId)->value('member_id')],
            [(int) $row->public_port_from, (int) $row->public_port_to, $row->protocol, (int) $row->enabled, (int) $row->created_by]);

        $post = fn (array $d) => $this->as()->from(route('public-port-forwards.create'))->post(route('public-port-forwards.store'), $this->data($d));
        $post([])->assertSessionHasErrors(['public_port_from' => 'Kolize portů — tento rozsah je již obsazen.']);
        $post(['protocol' => 'udp'])->assertSessionHasNoErrors();
        $post(['public_ip' => '203.0.113.1'])->assertSessionHasErrors(['public_ip' => 'Veřejná IP musí být vybrána ze seznamu povolených adres.']);
        $post(['private_ip' => '10.0.0.999'])->assertSessionHasErrors(['private_ip' => 'Neplatná privátní IPv4 adresa.']);
        $post(['public_port_from' => 70000, 'public_port_to' => 70000])->assertSessionHasErrors(['public_port_from' => 'Port musí být v rozsahu 1–65535.']);
        $post(['public_port_from' => 9000, 'public_port_to' => 8999, 'private_port_from' => 90, 'private_port_to' => 89])->assertSessionHasErrors(['public_port_from' => 'Neplatný veřejný rozsah portů.']);
        $post(['public_port_from' => 9000, 'public_port_to' => 9002])->assertSessionHasErrors(['private_port_from' => 'Veřejný a privátní rozsah portů musí mít stejnou velikost.']);
        $post(['public_port_from' => 9100, 'public_port_to' => 0, 'private_port_from' => 22, 'private_port_to' => 0])->assertSessionHasNoErrors();
        $single = DB::table('public_port_forwards')->where('public_ip', $this->pub)->where('public_port_from', 9100)->first();
        $this->assertSame([9100, 22], [(int) $single->public_port_to, (int) $single->private_port_to], 'prázdné „do" = „od"');

        $this->as()->get(route('public-port-forwards.edit', $row->id))->assertOk();
        $this->as()->put(route('public-port-forwards.update', $row->id), $this->data(['enabled' => '0']))->assertRedirect(route('public-port-forwards.index'));
        $this->assertSame(0, (int) DB::table('public_port_forwards')->where('id', $row->id)->value('enabled'));
        $this->assertNotNull(DB::table('public_port_forwards')->where('id', $row->id)->value('modified'));

        $ids = fn (string $q) => $this->as()->get(route('public-port-forwards.index', ['q' => $q]))->assertOk()->viewData('rows')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertContains((int) $row->id, $ids($this->pub));
        $this->assertContains((int) $row->id, $ids('8081'), 'port v rozsahu');
        $this->assertNotContains((int) $row->id, $ids('8082'));
        $this->as()->put(route('public-port-forwards.update', 999999999), $this->data())->assertNotFound();
    }

    public function test_smazani_a_prava(): void
    {
        $id = (int) DB::table('public_port_forwards')->insertGetId(['public_ip' => $this->pub, 'public_port_from' => 1, 'public_port_to' => 1, 'private_ip' => '10.245.1.6',
            'private_port_from' => 1, 'private_port_to' => 1, 'protocol' => 'tcp', 'enabled' => 1, 'created' => now()]);
        $this->acl(['edit_all/Network_Controller/public_ports']);
        $this->assertFalse($this->as()->get(route('public-port-forwards.index'))->viewData('canEdit'));
        $this->as()->get(route('public-port-forwards.create'))->assertForbidden();
        $this->as()->post(route('public-port-forwards.store'), $this->data())->assertForbidden();
        $this->as()->get(route('public-port-forwards.edit', $id))->assertForbidden();
        $this->acl(['view_all/Network_Controller/public_ports']);
        $this->as()->get(route('public-port-forwards.index'))->assertForbidden();

        $this->acl();
        $this->as()->get(route('public-port-forwards.destroy', $id))->assertRedirect(route('public-port-forwards.index'))->assertSessionHas('success', 'Port forward byl smazán.');
        $this->assertFalse(DB::table('public_port_forwards')->where('id', $id)->exists());
    }
}
