<?php

namespace Tests\Characterization\Access;

use App\Domains\Access\Actions\EnsurePppoeCredentialAction;
use App\Domains\Acl\AclService;
use App\Models\ConnectionRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\DatabaseTestCase;

/**
 * Charakterizace POST devices/add (storeWithTemplate) s connection_request_id —
 * registrace zařízení ze žádosti o připojení: žádost se schválí (+ zpráva 13)
 * a se zapnutým PPPoE dostanou přípojná rozhraní (IP s gateway=0) credential:
 * první PŘEVEZME username/heslo ze žádosti (instalátor ho už zadal do CPE),
 * další se vygenerují. Chyba PPPoE nesmí shodit vytvoření zařízení.
 *
 * Zachycuje SOUČASNÉ chování před přesunem PPPoE do listeneru domény Access.
 */
class DeviceFromConnectionRequestTest extends DatabaseTestCase
{
    private int $adminId;
    private int $memberId;
    private int $userId;
    private int $templateId;
    private int $subnetId;
    private int $crId;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        if (!$this->adminId || !$apId) {
            $this->markTestSkipped('chybí hlavní uživatel / adresní bod');
        }
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true);
        $acl->shouldReceive('flushUserCache')->andReturnNull();

        Setting::set('pppoe_enabled', '1');
        $this->tag = substr(md5(uniqid('', true)), 0, 6);

        $this->memberId = (int) DB::table('members')->insertGetId([
            'name' => 'Char CR', 'type' => 2, 'leaving_date' => '9999-12-31',
            'registration' => 1, 'locked' => 0, 'address_point_id' => $apId,
        ]);
        $this->userId = (int) DB::table('users')->insertGetId([
            'member_id' => $this->memberId, 'login' => 'char_cr_' . $this->tag,
            'password' => Hash::make('x'), 'type' => 1, 'application_password' => 'xxxxxxxx',
            'settings' => '', 'name' => 'Char', 'surname' => 'Cr', 'comment' => '',
        ]);
        $this->templateId = (int) DB::table('device_templates')->insertGetId([
            'enum_type_id' => 8, 'name' => 'char šablona',
            'values' => json_encode([
                '2' => ['count' => 1, 'items' => [['name' => 'ether1']], 'type' => 2, 'has_ip' => true, 'has_mac' => true],
                '3' => ['count' => 1, 'items' => [['name' => 'ether2']], 'type' => 3, 'has_ip' => true, 'has_mac' => true],
            ]),
        ]);
        $this->subnetId = (int) DB::table('subnets')->insertGetId([
            'name' => 'char cr', 'network_address' => '10.250.5.0', 'netmask' => '255.255.255.0', 'dhcp' => 0,
        ]);
        $this->crId = (int) DB::table('connection_requests')->insertGetId([
            'member_id' => $this->memberId, 'state' => ConnectionRequest::STATE_UNDECIDED, 'created_at' => now(),
            'pppoe_username' => 'char_cr_' . $this->tag, 'pppoe_secret' => \Tests\Support\Fake::secret('sekretzezadost'),
        ]);
    }

    private function mac(int $n): string
    {
        return sprintf('02:C5:%s:%s:%s:%02X', substr($this->tag, 0, 2), substr($this->tag, 2, 2), substr($this->tag, 4, 2), $n);
    }

    private function register(array $overrides = [], bool $secondIp = true)
    {
        $data = [
            'member_id'          => $this->memberId,
            'name'               => 'char-cpe-' . $this->tag,
            'type'               => 8,
            'device_template_id' => $this->templateId,
            'iface_name_0'       => 'ether1',
            'iface_mac_0'        => $this->mac(1),
            'iface_ip_0'         => '10.250.5.10',
            'iface_subnet_0'     => $this->subnetId,
            'iface_name_1'       => 'ether2',
            'iface_mac_1'        => $this->mac(2),
            'connection_request_id' => $this->crId,
        ];
        if ($secondIp) {
            $data += ['iface_ip_1' => '10.250.5.11', 'iface_subnet_1' => $this->subnetId];
        }
        return $this->actingAs(User::find($this->adminId))
            ->post(route('devices.store_template'), array_merge($data, $overrides));
    }

    private function deviceId(): int
    {
        return (int) DB::table('devices')->where('name', 'char-cpe-' . $this->tag)->value('id');
    }

    /** @return array<string, object> jméno rozhraní => pppoe_secrets řádek|null */
    private function secrets(): array
    {
        $out = [];
        foreach (DB::table('ifaces')->where('device_id', $this->deviceId())->get() as $if) {
            $out[$if->name] = DB::table('pppoe_secrets')->where('iface_id', $if->id)->first();
        }
        return $out;
    }

    public function test_zadost_se_schvali_a_zarizeni_navaze(): void
    {
        $this->register()->assertRedirect(route('devices.show', $this->deviceId()));

        $cr = DB::table('connection_requests')->where('id', $this->crId)->first();
        $this->assertSame(ConnectionRequest::STATE_APPROVED, (int) $cr->state);
        $this->assertSame($this->deviceId(), (int) $cr->device_id);
        $this->assertSame($this->adminId, (int) $cr->decided_user_id);
        $this->assertNotNull($cr->decided_at);
    }

    public function test_prvni_pripojne_rozhrani_prevezme_credential_ze_zadosti_dalsi_vygeneruje(): void
    {
        $this->register();
        $s = $this->secrets();

        $this->assertSame('char_cr_' . $this->tag, $s['ether1']->username);
        $this->assertSame(\Tests\Support\Fake::secret('sekretzezadost'), $s['ether1']->secret);
        $this->assertSame(1, (int) $s['ether1']->enabled);

        $this->assertNotNull($s['ether2']);
        $this->assertNotSame('char_cr_' . $this->tag, $s['ether2']->username);
        $this->assertNotSame('', (string) $s['ether2']->secret);
        $this->assertSame(1, (int) $s['ether2']->enabled);
    }

    /** Rozhraní bez IP není přípojné → credential nedostane. */
    public function test_rozhrani_bez_ip_nedostane_credential(): void
    {
        $this->register(secondIp: false);
        $s = $this->secrets();

        $this->assertNotNull($s['ether1']);
        $this->assertNull($s['ether2']);
    }

    public function test_pppoe_vypnuto_zadny_credential(): void
    {
        Setting::set('pppoe_enabled', '0');
        $this->register();

        $this->assertSame([null, null], array_values($this->secrets()));
        $this->assertSame(ConnectionRequest::STATE_APPROVED,
            (int) DB::table('connection_requests')->where('id', $this->crId)->value('state'));
    }

    public function test_bez_zadosti_zadny_credential(): void
    {
        $this->register(['connection_request_id' => '']);
        $this->assertSame([null, null], array_values($this->secrets()));
    }

    /**
     * Quirk: už rozhodnutá žádost se znovu neschválí, ale PPPoE se přesto
     * provede (blok čte žádost bez ohledu na stav).
     */
    public function test_rozhodnuta_zadost_se_neschvali_ale_pppoe_ano(): void
    {
        DB::table('connection_requests')->where('id', $this->crId)->update(['state' => ConnectionRequest::STATE_REJECTED]);
        $this->register();

        $this->assertSame(ConnectionRequest::STATE_REJECTED,
            (int) DB::table('connection_requests')->where('id', $this->crId)->value('state'));
        $this->assertNull(DB::table('connection_requests')->where('id', $this->crId)->value('device_id'));
        $this->assertSame('char_cr_' . $this->tag, $this->secrets()['ether1']->username);
    }

    /** Žádost bez credentialu (PPPoE zapnuté až po jejím podání) → vše se vygeneruje. */
    public function test_zadost_bez_credentialu_vygeneruje_vse(): void
    {
        DB::table('connection_requests')->where('id', $this->crId)->update(['pppoe_username' => null, 'pppoe_secret' => null]);
        $this->register();
        $s = $this->secrets();

        $this->assertNotNull($s['ether1']);
        $this->assertNotNull($s['ether2']);
        $this->assertNotSame($s['ether1']->username, $s['ether2']->username);
    }

    public function test_chyba_pppoe_neshodi_vytvoreni_zarizeni(): void
    {
        $this->mock(EnsurePppoeCredentialAction::class)
            ->shouldReceive('handle')->andThrow(new \RuntimeException('boom'));
        Log::spy();

        $this->register()->assertRedirect(route('devices.show', $this->deviceId()))
            ->assertSessionHas('success', 'Zařízení bylo úspěšně přidáno.');

        $this->assertNotSame(0, $this->deviceId());
        // první (adopt) proběhl, druhé (ensure) spadlo
        $this->assertNotNull($this->secrets()['ether1']);
        $this->assertNull($this->secrets()['ether2']);
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($msg, $ctx) => $msg === 'PPPoE credential při registraci selhal'
                && $ctx['device'] === $this->deviceId() && $ctx['error'] === 'boom'
        );
    }

    public function test_schvaleni_posle_zpravu_13_s_emailem(): void
    {
        $email = 'char_cr_' . $this->tag . '@example.test';
        $cid = (int) DB::table('contacts')->insertGetId(['type' => 20, 'value' => $email]);
        DB::table('users_contacts')->insert(['user_id' => $this->userId, 'contact_id' => $cid]);

        $this->register();

        $hasText = (string) DB::table('messages')->where('id', 13)->value('email_text') !== '';
        $this->assertSame($hasText ? 1 : 0, DB::table('email_queues')->where('to', $email)->count());
    }
}
