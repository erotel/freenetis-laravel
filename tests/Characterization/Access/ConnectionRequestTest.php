<?php

namespace Tests\Characterization\Access;

use App\Domains\Access\Onboarding\SnmpMacDetector;
use App\Domains\Acl\AclService;
use App\Models\ConnectionRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace žádostí o připojení (ConnectionRequestController) před
 * přesunem do domény Access: modul vypnutý / ACL (vše vs. vlastní), seznamy,
 * detail, žádost technikem (subnet + IP + MAC) i samoobslužná (detekce IP/MAC
 * z aktuálního připojení), notifikace správci + zpráva 12, schválení (→
 * registrace zařízení), zamítnutí (+ zpráva 14). PPPoE onboarding:
 * Access/DeviceFromConnectionRequestTest, Feature/Devices/Pppoe*Test.
 *
 * Čekající samoobslužná žádost bez IP (NULL) nesmí zablokovat volnost ostatních
 * IP (do 2026-10-16 `NOT IN` s NULL tvrdil, že žádná IP volná není).
 */
class ConnectionRequestTest extends DatabaseTestCase
{
    private int $adminId;
    private int $memberId;
    private int $userId;
    private int $subnetId;
    private int $templateId;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->acl();
        Setting::set('connection_request_enable', 1);
        Setting::set('pppoe_enabled', 0);
        Setting::set('connection_request_notify_email', 'noc@example.invalid');
        Setting::set('connection_request_device_types', '');
        DB::table('messages')->whereIn('id', [12, 14])->update(['email_text' => 'MSG {member_name}|{comment}']);
        $this->mock(SnmpMacDetector::class)->shouldReceive('detectForSubnet')->andReturn(null)->byDefault();
        // čisté prostředí: žádné čekající žádosti (v rollbacku testu)
        DB::table('connection_requests')->where('state', ConnectionRequest::STATE_UNDECIDED)->update(['state' => ConnectionRequest::STATE_REJECTED]);

        $this->tag = substr(md5(uniqid('', true)), 0, 6);
        $apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $this->memberId = (int) DB::table('members')->insertGetId(['name' => 'Char Zadatel', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $apId, 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $this->userId = (int) DB::table('users')->insertGetId(['member_id' => $this->memberId, 'login' => 'charcr_' . $this->tag, 'password' => Hash::make('x'),
            'type' => 1, 'application_password' => 'x', 'settings' => '', 'name' => 'Petr', 'surname' => 'Zadatel', 'comment' => '']);
        $cid = (int) DB::table('contacts')->insertGetId(['type' => 20, 'value' => "charcr{$this->tag}@example.invalid"]);
        DB::table('users_contacts')->insert(['user_id' => $this->userId, 'contact_id' => $cid]);
        $this->subnetId   = (int) DB::table('subnets')->insertGetId(['name' => 'char cr', 'network_address' => '10.250.22.0', 'netmask' => '255.255.255.0']);
        $this->templateId = (int) DB::table('device_templates')->insertGetId(['enum_type_id' => 8, 'name' => 'char cr', 'values' => '{}']);
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function as(?int $uid = null)
    {
        return $this->actingAs(User::find($uid ?? $this->adminId));
    }

    private function cr(array $c = []): int
    {
        return (int) DB::table('connection_requests')->insertGetId($c + ['member_id' => $this->memberId, 'state' => ConnectionRequest::STATE_UNDECIDED,
            'created_at' => now(), 'ip_address' => '10.250.22.' . random_int(100, 200), 'subnet_id' => $this->subnetId, 'mac_address' => '02:00:00:00:00:01']);
    }

    private function storeData(array $over = []): array
    {
        return $over + ['subnet_id' => $this->subnetId, 'ip_address' => '10.250.22.9', 'mac_address' => 'aa-bb-cc-dd-ee-0f',
            'device_type_id' => 8, 'device_template_id' => $this->templateId, 'comment' => 'chci net', 'member_id' => $this->memberId];
    }

    // ── modul, ACL, seznamy, detail ──────────────────────────────────────────

    public function test_vypnuty_modul_a_prava(): void
    {
        Setting::set('connection_request_enable', 0);
        $this->as()->get(route('connection_requests.index'))->assertForbidden();
        Setting::set('connection_request_enable', 1);
        $this->acl(['view_all/Connection_Requests_Controller/request', 'view_own/Connection_Requests_Controller/request']);
        $this->as()->get(route('connection_requests.index'))->assertForbidden();
    }

    public function test_seznam_vlastni_filtr_a_detail(): void
    {
        $mine  = $this->cr(['ip_address' => '10.250.22.31']);
        $other = $this->cr(['member_id' => 1, 'ip_address' => '10.250.22.32', 'state' => ConnectionRequest::STATE_REJECTED]);

        $ids = fn ($res) => collect($res->viewData('items')->items())->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertContains($other, $ids($this->as()->get(route('connection_requests.index', ['ip_address' => '10.250.22.3']))));
        $this->assertSame([$mine], $ids($this->as()->get(route('connection_requests.index', ['ip_address' => '10.250.22.3', 'state' => 0]))));

        $this->as()->get(route('connection_requests.show', $mine))->assertOk()->assertViewHas('canEdit', true);
        $this->as()->get(route('connection_requests.show', $other))->assertViewHas('canEdit', false, 'rozhodnutá → bez úprav');

        $this->acl(['view_all/Connection_Requests_Controller/request']);
        $this->assertSame([$mine], array_values(array_intersect([$mine, $other], $ids($this->as($this->userId)->get(route('connection_requests.index'))))));
        $this->as($this->userId)->get(route('connection_requests.show', $other))->assertForbidden();
        $this->as($this->userId)->get(route('connection_requests.by_member', $this->memberId))->assertOk()
            ->assertViewHas('items', fn ($i) => $i->pluck('id')->contains($mine))->assertViewHas('canAdd', true);
        $this->as($this->userId)->get(route('connection_requests.by_member', 1))->assertForbidden();
    }

    // ── žádost technikem ─────────────────────────────────────────────────────

    public function test_zadost_technikem_ulozi_notifikuje_a_potvrdi(): void
    {
        $this->as()->post(route('connection_requests.store'), $this->storeData())
            ->assertRedirect(route('connection_requests.by_member', $this->memberId))->assertSessionHas('success', 'Žádost o připojení byla odeslána.');

        $cr = DB::table('connection_requests')->where('ip_address', '10.250.22.9')->first();
        $this->assertSame([$this->memberId, $this->adminId, 0, $this->subnetId, 'AA:BB:CC:DD:EE:0F', 8, $this->templateId, 'chci net', null, null],
            [(int) $cr->member_id, (int) $cr->added_user_id, (int) $cr->state, (int) $cr->subnet_id, $cr->mac_address, (int) $cr->device_type_id,
             (int) $cr->device_template_id, $cr->comment, $cr->pppoe_username, $cr->pppoe_secret]);

        $noc = DB::table('email_queues')->where('to', 'noc@example.invalid')->orderByDesc('id')->first();
        $this->assertSame('Nová žádost o připojení — 10.250.22.9', $noc->subject);
        $this->assertSame("Byla podána nová žádost o připojení.\n\nČlen: Char Zadatel\nIP adresa: 10.250.22.9\nMAC adresa: AA:BB:CC:DD:EE:0F\n\nDetail: "
            . route('connection_requests.show', $cr->id), $noc->body);
        $this->assertSame('MSG Char Zadatel|chci net', DB::table('email_queues')->where('to', "charcr{$this->tag}@example.invalid")->value('body'));
    }

    /** Typ zařízení jen z číselníku zařízení (a povolených v nastavení); MAC jednotně s dvojtečkami. */
    public function test_typ_zarizeni_jen_povoleny(): void
    {
        $memberType = (int) DB::table('enum_types')->where('type_id', 1)->value('id');
        $this->as()->post(route('connection_requests.store'), $this->storeData(['device_type_id' => $memberType]))->assertSessionHasErrors('device_type_id');
        Setting::set('connection_request_device_types', '9:10');
        $this->as()->post(route('connection_requests.store'), $this->storeData())->assertSessionHasErrors('device_type_id');
        $this->as($this->userId)->post(route('connection_requests.request_store'), ['device_type_id' => 8, 'mac_address' => '02:00:00:00:00:09'])->assertSessionHasErrors('device_type_id');
        $this->assertFalse(DB::table('connection_requests')->where('ip_address', '10.250.22.9')->exists());
    }

    public function test_zadost_technikem_chyby_a_bez_prava_na_zarizeni(): void
    {
        $this->as()->post(route('connection_requests.store'), $this->storeData(['ip_address' => '10.9.9.9']))
            ->assertSessionHasErrors(['ip_address' => 'IP adresa není v rozsahu subnetu.']);
        $this->cr(['ip_address' => '10.250.22.9']);
        $this->as()->post(route('connection_requests.store'), $this->storeData())
            ->assertSessionHasErrors(['ip_address' => 'Pro tuto IP adresu již existuje čekající žádost.']);
        $this->as()->post(route('connection_requests.store'), $this->storeData(['mac_address' => 'xx']))->assertSessionHasErrors('mac_address');

        // bez práva na zařízení → žádost za sebe (member_id z formuláře se ignoruje)
        $this->acl(['new_all/Devices_Controller/devices']);
        Setting::set('pppoe_enabled', 1);
        $this->as($this->userId)->post(route('connection_requests.store'), $this->storeData(['ip_address' => '10.250.22.10', 'member_id' => 1]));
        $cr = DB::table('connection_requests')->where('ip_address', '10.250.22.10')->first();
        $this->assertSame($this->memberId, (int) $cr->member_id);
        $this->assertNotEmpty($cr->pppoe_username, 'PPPoE zapnuto → credential na žádosti');
        $this->assertNotEmpty($cr->pppoe_secret);
    }

    public function test_formular_technika_rozsah_a_volnost_ip(): void
    {
        $this->as()->get(route('connection_requests.create', [$this->subnetId, '10.9.9.9']))->assertStatus(400);
        DB::table('ip_addresses')->insert(['subnet_id' => $this->subnetId, 'ip_address' => '10.250.22.20']);
        $this->as()->from(route('connection_requests.index'))->get(route('connection_requests.create', [$this->subnetId, '10.250.22.20']))
            ->assertRedirect(route('connection_requests.index'))->assertSessionHas('warning', 'IP adresa již není volná nebo má čekající žádost.');
        $this->cr(['ip_address' => '10.250.22.21']);
        $this->as()->from(route('connection_requests.index'))->get(route('connection_requests.create', [$this->subnetId, '10.250.22.21']))
            ->assertSessionHas('warning');

        $this->as()->get(route('connection_requests.create', [$this->subnetId, '10.250.22.22']))->assertOk()
            ->assertViewHas('ipAddress', '10.250.22.22')->assertViewHas('canEditDevices', true)->assertViewHas('detectedMac', null)
            ->assertViewHas('members', fn ($m) => $m->get($this->memberId) === 'Zadatel Petr');
    }

    public function test_cekajici_zadost_bez_ip_neblokuje_ostatni_ip(): void
    {
        $this->cr(['ip_address' => null, 'subnet_id' => null]);
        $this->as()->get(route('connection_requests.create', [$this->subnetId, '10.250.22.23']))->assertOk()
            ->assertViewHas('ipAddress', '10.250.22.23');
    }

    // ── samoobslužná žádost ──────────────────────────────────────────────────

    public function test_samoobsluzna_bez_detekce_vyzaduje_mac(): void
    {
        $this->as($this->userId)->get(route('connection_requests.request'))->assertOk()->assertViewHas('detected', null);
        $this->as($this->userId)->post(route('connection_requests.request_store'), ['comment' => 'x'])->assertSessionHasErrors('mac_address');

        $this->as($this->userId)->post(route('connection_requests.request_store'), ['mac_address' => 'aa:bb:cc:dd:ee:01', 'comment' => 'doma'])
            ->assertRedirect(route('connection_requests.by_member', $this->memberId))->assertSessionHas('success', 'Žádost o připojení byla odeslána. Ozveme se vám.');
        $cr = DB::table('connection_requests')->where('member_id', $this->memberId)->orderByDesc('id')->first();
        $this->assertSame([null, null, 'AA:BB:CC:DD:EE:01', $this->userId], [$cr->ip_address, $cr->subnet_id, $cr->mac_address, (int) $cr->added_user_id]);

        $noc = DB::table('email_queues')->where('to', 'noc@example.invalid')->orderByDesc('id')->first();
        $this->assertSame('Nová žádost o připojení — Char Zadatel', $noc->subject);
        $this->assertStringContainsString("IP adresa: — doplní technik\nMAC adresa: AA:BB:CC:DD:EE:01\nPoznámka: doma", $noc->body);
    }

    public function test_samoobsluzna_s_detekci_ip_a_mac_z_radacct(): void
    {
        Setting::set('pppoe_enabled', 1);
        DB::table('radacct')->insert(['acctsessionid' => '__selftest_cr_' . $this->tag, 'acctuniqueid' => md5('cr' . $this->tag), 'username' => 'x',
            'framedipaddress' => '10.250.22.44', 'callingstationid' => 'de-ad-be-ef-00-44', 'nasipaddress' => '10.133.31.70', 'acctstarttime' => now(), 'acctstoptime' => null]);

        $this->as($this->userId)->withServerVariables(['REMOTE_ADDR' => '10.250.22.44'])->get(route('connection_requests.request'))
            ->assertViewHas('detected', ['subnet_id' => $this->subnetId, 'ip' => '10.250.22.44', 'mac' => 'DE:AD:BE:EF:00:44']);
        $this->as($this->userId)->withServerVariables(['REMOTE_ADDR' => '10.250.22.44'])->post(route('connection_requests.request_store'), ['mac_address' => 'ignorovano'])
            ->assertSessionHasNoErrors();
        $cr = DB::table('connection_requests')->where('ip_address', '10.250.22.44')->first();
        $this->assertSame([$this->subnetId, 'DE:AD:BE:EF:00:44'], [(int) $cr->subnet_id, $cr->mac_address], 'detekovaná MAC má přednost, pole se nekontroluje');
    }

    // ── schválení / zamítnutí ────────────────────────────────────────────────

    public function test_schvaleni_presmeruje_na_zarizeni_a_zamitnuti(): void
    {
        $cr = $this->cr(['comment' => 'proc']);
        $this->as()->get(route('connection_requests.approve', $cr))->assertRedirect(route('devices.create_from_cr', $cr))
            ->assertSessionHas('info', 'Vytvořte zařízení pro žadatele — po uložení bude žádost automaticky schválena.');

        $this->as()->delete(route('connection_requests.reject', $cr))->assertRedirect(route('connection_requests.show', $cr))
            ->assertSessionHas('success', 'Žádost byla zamítnuta.');
        $row = DB::table('connection_requests')->where('id', $cr)->first();
        $this->assertSame([ConnectionRequest::STATE_REJECTED, $this->adminId], [(int) $row->state, (int) $row->decided_user_id]);
        $this->assertNotNull($row->decided_at);
        $this->assertSame('MSG Char Zadatel|proc', DB::table('email_queues')->where('to', "charcr{$this->tag}@example.invalid")->orderByDesc('id')->value('body'));

        $this->as()->get(route('connection_requests.approve', $cr))->assertStatus(400);
        $this->as()->delete(route('connection_requests.reject', $cr))->assertStatus(400);
        $this->acl(['edit_all/Connection_Requests_Controller/request']);
        $this->as()->delete(route('connection_requests.reject', $this->cr()))->assertForbidden();
    }
}
