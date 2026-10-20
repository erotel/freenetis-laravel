<?php

namespace Tests\Characterization\Network;

use App\Domains\Acl\AclService;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace zápisové strany DeviceController před přesunem do domény
 * Network. Registrace ze žádosti o připojení: Access/DeviceFromConnectionRequestTest;
 * přesun majitele: Feature/Devices/DeviceMoveAllowedSubnetTest; login/heslo/WPA:
 * DeviceStoreCredentialsAclTest + DeviceWpaKeyTest. Tady zbytek:
 *
 *   storeWithTemplate  validace rozhraní ze šablony, zařízení + rozhraní + IPv4
 *                      (+ odvozená IPv6), DHCP podsíť k přegenerování, povolené podsítě
 *   store / update     základní pole, adresa umístění, datum koupě, hlavní uživatel
 *   destroy            kaskáda, audit na členovi, DHCP, povolené podsítě
 *   technici           přidat (idempotentně) / odebrat (audit)
 */
class DeviceWriteTest extends DatabaseTestCase
{
    private int $adminId;
    private int $memberId;
    private int $userId;
    private int $townId;
    private int $templateId;
    private int $subnetId;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $apId          = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $this->townId  = (int) DB::table('towns')->value('id');
        if (!$this->adminId || !$apId || !$this->townId) {
            $this->markTestSkipped('chybí admin / adresní bod / obec');
        }
        $this->acl();
        Setting::set('ipv6_prefix', '2a07:9c0');
        Setting::set('ipv6_mask', '56');
        $this->tag = substr(md5(uniqid('', true)), 0, 6);

        $this->memberId = (int) DB::table('members')->insertGetId([
            'name' => 'Char Dev', 'type' => 2, 'leaving_date' => '9999-12-31', 'registration' => 1, 'locked' => 0, 'address_point_id' => $apId,
        ]);
        $this->userId = $this->user($this->memberId, 1);
        $this->templateId = (int) DB::table('device_templates')->insertGetId([
            'enum_type_id' => 8, 'name' => 'char dev šablona',
            'values' => json_encode([
                '2' => ['count' => 1, 'items' => [['name' => 'ether1']], 'type' => 2, 'has_ip' => true, 'has_mac' => true],
                '3' => ['count' => 1, 'items' => [['name' => 'ether2']], 'type' => 3, 'has_ip' => true, 'has_mac' => true],
                '4' => ['count' => 0, 'items' => [], 'type' => 4, 'has_ip' => false, 'has_mac' => true],
            ]),
        ]);
        $this->subnetId = (int) DB::table('subnets')->insertGetId([
            'name' => 'char dev', 'network_address' => '10.250.12.0', 'netmask' => '255.255.255.0', 'dhcp' => 1, 'dhcp_expired' => 0,
        ]);
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function user(int $memberId, int $type): int
    {
        return (int) DB::table('users')->insertGetId([
            'member_id' => $memberId, 'login' => 'chardev_' . uniqid(), 'password' => Hash::make('x'), 'type' => $type,
            'application_password' => 'xxxxxxxx', 'settings' => '', 'name' => 'Char', 'surname' => 'Dev', 'comment' => '',
        ]);
    }

    private function mac(int $n): string
    {
        return sprintf('02:D7:%s:%s:%s:%02X', substr($this->tag, 0, 2), substr($this->tag, 2, 2), substr($this->tag, 4, 2), $n);
    }

    private function as()
    {
        return $this->actingAs(User::find($this->adminId))->from(route('devices.add'));
    }

    private function templateData(array $over = []): array
    {
        return array_merge([
            'member_id' => $this->memberId, 'name' => 'char-dev-' . $this->tag, 'type' => 8,
            'device_template_id' => $this->templateId, 'comment' => 'pozn',
            'iface_name_0' => 'ether1', 'iface_mac_0' => $this->mac(1), 'iface_ip_0' => '10.250.12.10', 'iface_subnet_0' => $this->subnetId,
            'iface_name_1' => 'ether2', 'iface_mac_1' => '',
        ], $over);
    }

    private function device(): ?object
    {
        return DB::table('devices')->where('name', 'char-dev-' . $this->tag)->first();
    }

    // ── založení ze šablony ──────────────────────────────────────────────────

    public function test_sablona_vytvori_zarizeni_rozhrani_ip_ipv6_a_povoli_podsit(): void
    {
        $res = $this->as()->post(route('devices.store_template'), $this->templateData());
        $d = $this->device();
        $this->assertNotNull($d);
        $res->assertRedirect(route('devices.show', $d->id))->assertSessionHas('success', 'Zařízení bylo úspěšně přidáno.');

        $this->assertSame([$this->userId, 8, now()->toDateString(), 'pozn', null], [(int) $d->user_id, (int) $d->type, $d->buy_date, $d->comment, $d->address_point_id]);

        $ifaces = DB::table('ifaces')->where('device_id', $d->id)->orderBy('id')->get();
        $this->assertSame([['ether1', 2, $this->mac(1)], ['ether2', 3, null]], $ifaces->map(fn ($i) => [$i->name, (int) $i->type, $i->mac])->all(),
            'rozhraní s count 0 se nevytvoří; prázdná MAC = null');

        $ip = DB::table('ip_addresses')->where('iface_id', $ifaces[0]->id)->first();
        $this->assertSame(['10.250.12.10', $this->subnetId, $this->memberId, 0, 0, 0], [$ip->ip_address, (int) $ip->subnet_id, (int) $ip->member_id, (int) $ip->dhcp, (int) $ip->gateway, (int) $ip->service]);
        $this->assertSame('2a07:9c0:c:a00::/56', DB::table('ip6_addresses')->where('iface_id', $ifaces[0]->id)->value('ip_address'), 'IPv6 odvozená z 10.x');
        $this->assertFalse(DB::table('ip_addresses')->where('iface_id', $ifaces[1]->id)->exists());

        $this->assertSame(1, (int) DB::table('subnets')->where('id', $this->subnetId)->value('dhcp_expired'));
        $this->assertTrue(DB::table('allowed_subnets')->where('member_id', $this->memberId)->where('subnet_id', $this->subnetId)->exists());
    }

    public function test_sablona_validace(): void
    {
        $this->as()->post(route('devices.store_template'), $this->templateData(['iface_name_1' => '']))->assertSessionHasErrors('iface_name_1');
        $this->as()->post(route('devices.store_template'), $this->templateData(['iface_mac_0' => 'zz']))
            ->assertSessionHasErrors(['iface_mac_0' => 'MAC adresa musí být ve formátu aa:bb:cc:dd:ee:ff.']);
        $this->as()->post(route('devices.store_template'), $this->templateData(['iface_subnet_0' => '']))->assertSessionHasErrors('iface_subnet_0');
        $this->as()->post(route('devices.store_template'), $this->templateData(['iface_mac_0' => '']))
            ->assertSessionHasErrors(['iface_mac_0' => 'Alespoň jedno rozhraní musí mít vyplněnou MAC adresu.']);

        $this->as()->post(route('devices.store_template'), $this->templateData())->assertSessionHasNoErrors();
        $this->as()->post(route('devices.store_template'), $this->templateData(['name' => 'jine', 'iface_mac_0' => $this->mac(1)]))
            ->assertSessionHasErrors(['iface_mac_0' => 'Tato MAC adresa je již použita.']);
        $this->as()->post(route('devices.store_template'), $this->templateData(['name' => 'jine', 'iface_mac_0' => $this->mac(9)]))
            ->assertSessionHasErrors(['iface_ip_0' => 'IP adresa 10.250.12.10 je již použita v tomto subnetu.']);
        $this->as()->post(route('devices.store_template'), $this->templateData(['name' => 'jine', 'iface_mac_0' => $this->mac(8), 'iface_ip_0' => '10.199.0.1']))
            ->assertSessionHasErrors(['iface_ip_0' => 'IP adresa 10.199.0.1 neleží v podsíti 10.250.12.0/24.']);
        $this->as()->post(route('devices.store_template'), $this->templateData(['name' => 'jine', 'iface_mac_0' => $this->mac(7), 'iface_ip_0' => '2a07:9c0::1']))
            ->assertSessionHasErrors('iface_ip_0');
        $this->assertSame(1, DB::table('devices')->where('user_id', $this->userId)->count());
    }

    public function test_sablona_clen_bez_hlavniho_uzivatele_a_bez_prava(): void
    {
        $noMain = (int) DB::table('members')->insertGetId(['name' => 'Bez', 'type' => 2, 'leaving_date' => '9999-12-31', 'registration' => 1, 'locked' => 0,
            'address_point_id' => DB::table('members')->where('id', $this->memberId)->value('address_point_id')]);
        $this->user($noMain, 2);
        $this->as()->post(route('devices.store_template'), $this->templateData(['member_id' => $noMain]))
            ->assertSessionHasErrors(['member_id' => 'Zvolený člen nemá hlavního uživatele.']);

        $this->acl(['new_all/Devices_Controller/devices']);
        $this->as()->post(route('devices.store_template'), $this->templateData())->assertForbidden();
    }

    public function test_sablona_adresa_umisteni(): void
    {
        $this->as()->post(route('devices.store_template'), $this->templateData(['town_id' => $this->townId, 'street_number' => '77']));
        $ap = DB::table('address_points')->where('id', $this->device()->address_point_id)->first();
        $this->assertSame([$this->townId, '77'], [(int) $ap->town_id, $ap->street_number]);
    }

    // ── založení / úprava bez šablony ────────────────────────────────────────

    public function test_store_zakladni(): void
    {
        $this->as()->post(route('devices.store'), [
            'member_id' => $this->memberId, 'name' => 'char-dev-' . $this->tag, 'type' => 8, 'trade_name' => 'RB',
            'price' => '1200', 'payment_rate' => '100', 'comment' => 'c', 'town_id' => $this->townId, 'street_number' => '5',
        ])->assertSessionHas('success', 'Zařízení bylo úspěšně přidáno.');
        $d = $this->device();
        $this->assertSame([$this->userId, 'RB', 1200.0, 100.0, now()->toDateString()], [(int) $d->user_id, $d->trade_name, (float) $d->price, (float) $d->payment_rate, $d->buy_date]);
        $this->assertSame('5', DB::table('address_points')->where('id', $d->address_point_id)->value('street_number'));

        $this->as()->post(route('devices.store'), ['member_id' => $this->memberId, 'name' => 'x', 'type' => 8, 'buy_date' => '2020-02-02'])->assertSessionHasNoErrors();
        $this->assertSame('2020-02-02', DB::table('devices')->where('user_id', $this->userId)->where('name', 'x')->value('buy_date'));
    }

    public function test_update_pole_adresa_a_chyby(): void
    {
        $dev = (int) DB::table('devices')->insertGetId(['name' => 'char-dev-' . $this->tag, 'type' => 8, 'user_id' => $this->userId, 'buy_date' => '2020-01-01']);
        $this->as()->put(route('devices.update', $dev), [
            'member_id' => $this->memberId, 'name' => 'char-dev-' . $this->tag, 'type' => 9, 'comment' => 'nove', 'town_id' => $this->townId, 'street_number' => '8',
        ])->assertRedirect(route('devices.show', $dev))->assertSessionHas('success', 'Zařízení bylo úspěšně upraveno.');
        $d = $this->device();
        $this->assertSame([9, 'nove', '2020-01-01'], [(int) $d->type, $d->comment, $d->buy_date]);
        $this->assertSame('8', DB::table('address_points')->where('id', $d->address_point_id)->value('street_number'));

        $this->as()->put(route('devices.update', 999999999), ['member_id' => $this->memberId, 'name' => 'x', 'type' => 8])->assertNotFound();
        $this->acl(['edit_all/Devices_Controller/devices']);
        $this->as()->put(route('devices.update', $dev), ['member_id' => $this->memberId, 'name' => 'x', 'type' => 8])->assertForbidden();
    }

    // ── smazání ──────────────────────────────────────────────────────────────

    public function test_smazani_kaskada_audit_dhcp_a_povolene_podsite(): void
    {
        $this->as()->post(route('devices.store_template'), $this->templateData());
        $d = $this->device();
        $ifaceIds = DB::table('ifaces')->where('device_id', $d->id)->pluck('id');
        DB::table('subnets')->where('id', $this->subnetId)->update(['dhcp_expired' => 0]);

        $this->as()->delete(route('devices.destroy', $d->id))
            ->assertRedirect(route('devices.by_user', $this->userId))->assertSessionHas('success', 'Zařízení bylo smazáno.');

        $this->assertNull($this->device());
        $this->assertSame(0, DB::table('ifaces')->whereIn('id', $ifaceIds)->count());
        $this->assertSame(0, DB::table('ip_addresses')->whereIn('iface_id', $ifaceIds)->count());
        $this->assertSame(0, DB::table('ip6_addresses')->whereIn('iface_id', $ifaceIds)->count());
        $this->assertSame(1, (int) DB::table('subnets')->where('id', $this->subnetId)->value('dhcp_expired'));
        $this->assertFalse(DB::table('allowed_subnets')->where('member_id', $this->memberId)->where('subnet_id', $this->subnetId)->exists());

        $audit = DB::table('audit_logs')->where('auditable_type', 'members')->where('auditable_id', $this->memberId)->where('action', 'device_removed')->first();
        $this->assertSame(['device_id' => (int) $d->id, 'device_name' => 'char-dev-' . $this->tag, 'ip_adresy' => ['10.250.12.10']], json_decode($audit->old_values, true));

        $this->as()->delete(route('devices.destroy', 999999999))->assertNotFound();
    }

    public function test_smazani_bez_prava_403(): void
    {
        $dev = (int) DB::table('devices')->insertGetId(['name' => 'char-dev-' . $this->tag, 'type' => 8, 'user_id' => $this->userId]);
        $this->acl(['delete_all/Devices_Controller/devices']);
        $this->as()->delete(route('devices.destroy', $dev))->assertForbidden();
        $this->assertNotNull($this->device());
    }

    // ── technici ─────────────────────────────────────────────────────────────

    public function test_technici_pridat_idempotentne_a_odebrat(): void
    {
        $dev  = (int) DB::table('devices')->insertGetId(['name' => 'char-dev-' . $this->tag, 'type' => 8, 'user_id' => $this->userId]);
        $tech = $this->user($this->memberId, 2);

        foreach ([1, 2] as $_) {
            $this->as()->post(route('devices.engineers.add', $dev), ['user_id' => $tech])
                ->assertRedirect(route('devices.show', $dev))->assertSessionHas('success', 'Technik byl přidán.');
        }
        $this->assertSame(1, DB::table('device_engineers')->where('device_id', $dev)->where('user_id', $tech)->count());

        $this->as()->delete(route('devices.engineers.remove', [$dev, $tech]))->assertSessionHas('success', 'Technik byl odebrán.');
        $this->assertFalse(DB::table('device_engineers')->where('device_id', $dev)->exists());
        $audit = DB::table('audit_logs')->where('auditable_type', 'device_engineers')->where('auditable_id', $dev)->where('action', 'deleted')->first();
        $this->assertSame(['device_id' => $dev, 'user_id' => $tech], json_decode($audit->old_values, true));

        $this->acl(['new_all/Devices_Controller/engineer', 'delete_all/Devices_Controller/engineer']);
        $this->as()->post(route('devices.engineers.add', $dev), ['user_id' => $tech])->assertForbidden();
        $this->as()->delete(route('devices.engineers.remove', [$dev, $tech]))->assertForbidden();
    }
}
