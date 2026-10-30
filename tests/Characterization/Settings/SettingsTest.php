<?php

namespace Tests\Characterization\Settings;

use App\Domains\Acl\AclService;
use App\Domains\Members\SledovaniTv\SledovaniTvService;
use App\Models\GponOlt;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\DatabaseTestCase;

/**
 * Charakterizace Nastavení (SettingController) před rozdělením do domén:
 * načtení všech záložek (výchozí hodnoty, BCC řádky, směrování bank. účtů,
 * auto-generace API tokenů), uložení každé záložky (validace, checkboxy,
 * normalizace, tajné hodnoty se prázdným polem nepřepisují, uploady),
 * regenerace tokenů, izolace LAN, GPON OLT CRUD, SledovaniTV sync a ACL.
 */
class SettingsTest extends DatabaseTestCase
{
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
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

    private function save(string $route, array $data, array $params = [])
    {
        return $this->as()->put(route($route, $params), $data);
    }

    // ── index ────────────────────────────────────────────────────────────────

    public function test_index_nacte_vsechny_zalozky(): void
    {
        $ba = (int) DB::table('bank_accounts')->insertGetId(['name' => 'AAA char', 'member_id' => 1, 'account_nr' => '1', 'bank_nr' => '2010', 'payment_purpose' => 1]);
        Setting::set('bank_account_member_type_2', $ba);
        Setting::set('bank_account_member_type_90', 0);
        for ($i = 0; $i < 10; $i++) {
            foreach (['message_id', 'subject_prefix', 'address'] as $k) {
                Setting::set("email_bcc_rule_{$i}_{$k}", '');
            }
        }
        Setting::set('email_bcc_rule_1_address', 'bcc@example.test');
        Setting::set('dhcp_api_token', '');
        Setting::set('web_interface_api_token', '');
        DB::table('config')->whereIn('name', ['otp_ttl_min', 'pdf_sign_pass', 'contract_email_vop_pdf', 'sms_driver_state3'])->delete();
        Setting::set('sledovanitv_last_sync', '2026-10-01 10:00');

        $res = $this->as()->get(route('settings.index', ['tab' => 'sms']))->assertOk()->assertViewIs('settings.index');
        $v = fn ($k) => $res->viewData($k);

        $this->assertSame('sms', $v('activeTab'));
        $this->assertSame([2 => 'Zákazník (typ 2)', 90 => 'Člen (typ 90)'], $v('memberTypes'));
        $this->assertSame(['label' => 'Zákazník (typ 2)', 'key' => 'bank_account_member_type_2', 'bank_account_id' => $ba, 'payment_purpose' => 1], $v('routing')[2]);
        $this->assertSame(0, $v('routing')[90]['payment_purpose']);
        $this->assertTrue($v('bankAccounts')->every(fn ($b) => DB::table('bank_accounts')->where('id', $b->id)->value('member_id') == 1), 'jen účty spolku');
        $this->assertSame(DB::table('bank_accounts')->where('member_id', 1)->orderBy('name')->pluck('id')->map(fn ($i) => (int) $i)->all(), $v('bankAccounts')->pluck('id')->all(), 'podle jména');

        $this->assertSame([
            ['message_id' => '', 'subject_prefix' => '', 'address' => 'bcc@example.test'],
            ['message_id' => '', 'subject_prefix' => '', 'address' => ''],
            ['message_id' => '', 'subject_prefix' => '', 'address' => ''],
        ], $v('bccRules'), 'neprázdná pravidla + doplnit na 3');
        $this->assertSame([...\App\Http\Controllers\SettingController::EMAIL_KEYS, 'email_password_set'], array_keys($v('emailSettings')));
        $this->assertSame(\App\Http\Controllers\SettingController::FINANCE_KEYS, array_keys($v('financeSettings')));
        $this->assertSame(\App\Http\Controllers\SettingController::SYSTEM_KEYS, array_keys($v('systemSettings')));
        $this->assertSame(\App\Http\Controllers\SettingController::USERS_KEYS, array_keys($v('usersSettings')));
        $this->assertSame(\App\Http\Controllers\SettingController::NETWORK_KEYS, array_keys($v('networkSettings')));
        $this->assertSame(\App\Http\Controllers\SettingController::REGISTRATION_KEYS, array_keys($v('registrationSettings')));
        $this->assertSame(['gpon_enabled'], array_keys($v('gponSettings')));

        $this->assertSame(32, strlen($v('dhcpApiToken')), 'token vygenerován');
        $this->assertSame($v('dhcpApiToken'), Setting::get('dhcp_api_token'));
        $this->assertSame(32, strlen($v('webInterfaceApiToken')));
        $this->assertSame($v('webInterfaceApiToken'), Setting::get('web_interface_api_token'));

        $sm = $v('smlouvySettings');
        $this->assertSame(\App\Http\Controllers\SettingController::SMLOUVY_KEYS, array_keys($sm));
        $this->assertSame((string) config('services.contracts.otp_ttl_min', 5), $sm['otp_ttl_min'], 'default z configu');
        $this->assertSame('', $sm['pdf_sign_pass']);
        $this->assertSame('storage/app/private/contract-attachments/vop.pdf', $sm['contract_email_vop_pdf']);

        $this->assertSame('1', $v('smsDriverSettings')[3]['state'], 'výchozí stav ovladače');
        $this->assertSame([3, 5], array_keys($v('smsDriverSettings')));
        $this->assertSame(['sms_enabled', 'sms_sender_number', 'sms_driver'], array_keys($v('smsSettings')));
        $this->assertSame('2026-10-01 10:00', $v('sledovanitvSettings')['last_sync']);
        $this->assertSame(['sledovanitv_enabled', 'sledovanitv_partner', 'sledovanitv_password', 'last_sync', 'last_sync_status'], array_keys($v('sledovanitvSettings')));
        $this->assertSame(DB::table('gpon_olts')->count(), $v('gponOlts')->count());
        $this->assertSame(DB::table('fees as f')->join('enum_types as et', 'et.id', '=', 'f.type_id')->whereRaw("LOWER(et.value) = 'regular member fee'")
            ->orderBy('f.name')->pluck('f.id')->all(), $v('feesForSelect')->pluck('id')->all(), 'jen pravidelné členské');
        $this->assertSame(['id', 'name', 'fee', 'from', 'to'], array_keys((array) $v('feesForSelect')->first()));
        $this->assertSame(DB::table('speed_classes')->count(), $v('speedClassesFinance')->count());
        $this->assertSame('banka', $this->as()->get(route('settings.index'))->viewData('activeTab'));
    }

    public function test_index_bez_prava(): void
    {
        $this->acl(['view_all/Settings_Controller/finance_settings']);
        $this->as()->get(route('settings.index'))->assertForbidden();
    }

    // ── banka ────────────────────────────────────────────────────────────────

    public function test_smerovani_bankovnich_uctu(): void
    {
        $a = (int) DB::table('bank_accounts')->insertGetId(['name' => 'char a', 'member_id' => 1, 'account_nr' => '1', 'bank_nr' => '2010', 'payment_purpose' => 0]);
        $b = (int) DB::table('bank_accounts')->insertGetId(['name' => 'char b', 'member_id' => 1, 'account_nr' => '2', 'bank_nr' => '2010', 'payment_purpose' => 1]);

        $this->save('settings.update', ['routing_2' => $a, 'payment_purpose_2' => 'on', 'routing_90' => $b, 'default_bank_account_id' => $b, 'pohoda_accountant_email' => 'u@example.test'])
            ->assertRedirect(route('settings.index'))->assertSessionHas('success', 'Nastavení bylo uloženo.');
        $this->assertSame([(string) $a, (string) $b, (string) $b, 'u@example.test'], [Setting::get('bank_account_member_type_2'), Setting::get('bank_account_member_type_90'),
            Setting::get('bank_account_default_import'), Setting::get('pohoda_accountant_email')]);
        $this->assertSame([1, 0], [(int) DB::table('bank_accounts')->where('id', $a)->value('payment_purpose'), (int) DB::table('bank_accounts')->where('id', $b)->value('payment_purpose')]);

        $this->save('settings.update', []);
        $this->assertSame(['0', '0', '0', ''], [Setting::get('bank_account_member_type_2'), Setting::get('bank_account_member_type_90'),
            Setting::get('bank_account_default_import'), Setting::get('pohoda_accountant_email')]);
        $this->assertSame(1, (int) DB::table('bank_accounts')->where('id', $a)->value('payment_purpose'), 'bez účtu se purpose nemění');
    }

    // ── e-mail ───────────────────────────────────────────────────────────────

    public function test_email_a_bcc_pravidla(): void
    {
        Storage::fake('local');
        Setting::set('email_bcc_rule_7_address', 'stare@example.test');

        $this->as()->put(route('settings.update-email'), [
            'email_enabled' => '1', 'email_hostname' => 'smtp.example.test', 'email_password' => 's3cret',
            'registration_summary_pdf' => 'rucne.pdf',
            'bcc_message_id' => ['4', '', '9'], 'bcc_subject_prefix' => ['', '', ' [X] '], 'bcc_address' => [' a@example.test ', '', ''],
            'registration_summary_pdf_file' => UploadedFile::fake()->create('Shrnutí smlouvy (v2).pdf', 10, 'application/pdf'),
        ])->assertRedirect(route('settings.index', ['tab' => 'email']))->assertSessionHas('success', 'Nastavení emailu bylo uloženo.');

        $this->assertSame(['1', 'smtp.example.test', 's3cret', '', 'Shrnut_smlouvy_v2_.pdf'], [Setting::get('email_enabled'), Setting::get('email_hostname'),
            Setting::get('email_password'), Setting::get('email_port'), Setting::get('registration_summary_pdf')]);
        Storage::disk('local')->assertExists('Shrnut_smlouvy_v2_.pdf');
        $rules = fn ($i) => [Setting::get("email_bcc_rule_{$i}_message_id"), Setting::get("email_bcc_rule_{$i}_subject_prefix"), Setting::get("email_bcc_rule_{$i}_address")];
        $this->assertSame([['4', '', 'a@example.test'], ['9', '[X]', ''], ['', '', ''], ['', '', '']], [$rules(0), $rules(1), $rules(2), $rules(7)], 'zhuštěno, staré smazané');

        $this->as()->put(route('settings.update-email'), ['registration_summary_pdf_file' => UploadedFile::fake()->create('x.txt', 1, 'text/plain')])
            ->assertSessionHasErrors('registration_summary_pdf_file');

        // heslo se do stránky neposílá; uložení s prázdným polem ho ponechá
        $secret = \Tests\Support\Fake::secret('smtp');
        Setting::set('email_password', $secret);
        $html = $this->as()->get(route('settings.index', ['tab' => 'email']))->assertOk()->getContent();
        $this->assertStringNotContainsString($secret, $html);
        $this->assertStringContainsString('•••••••• (beze změny)', $html);
        $this->as()->put(route('settings.update-email'), ['email_hostname' => 'smtp2.example.test', 'email_password' => ''])->assertSessionHasNoErrors();
        $this->assertSame(['smtp2.example.test', $secret], [Setting::get('email_hostname'), Setting::get('email_password')]);
    }

    // ── finance ──────────────────────────────────────────────────────────────

    public function test_finance_a_cenik_tarifu(): void
    {
        $sc = (int) DB::table('speed_classes')->insertGetId(['name' => 'char', 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => 100]);
        $sc2 = (int) DB::table('speed_classes')->insertGetId(['name' => 'char2', 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => 100]);
        $sc3 = (int) DB::table('speed_classes')->insertGetId(['name' => 'char3', 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => 100]);

        $this->save('settings.update-finance', ['deduct_day' => 26, 'finance_enabled' => '1', 'default_fee_member_type_2' => '7',
            'speed_price' => [$sc => '349,50', $sc2 => '', $sc3 => 'abc']])
            ->assertRedirect(route('settings.index', ['tab' => 'finance']))->assertSessionHas('success', 'Nastavení financí bylo uloženo.');
        $this->assertSame(['0', '26', '1', '7', '', '0', '14', ''], array_map(fn ($k) => Setting::get($k), ['deduct_fees_automatically_enabled', 'deduct_day', 'finance_enabled',
            'default_fee_member_type_2', 'default_fee_member_type_90', 'payment_blocked_redirect_enabled', 'pending_termination_day', 'admin_notification_email']));
        $this->assertEqualsWithDelta(349.5, (float) DB::table('speed_classes')->where('id', $sc)->value('price'), 0.001);
        $this->assertNull(DB::table('speed_classes')->where('id', $sc2)->value('price'), 'prázdné = NULL');
        $this->assertEqualsWithDelta(100, (float) DB::table('speed_classes')->where('id', $sc3)->value('price'), 0.001, 'nečíselné ignorováno');

        $this->save('settings.update-finance', ['deduct_day' => 26, 'deduct_fees_automatically_enabled' => 'on', 'payment_blocked_redirect_enabled' => '1',
            'pending_termination_day' => 10, 'admin_notification_email' => 'admin@example.test']);
        $this->assertSame(['1', '1', '10', 'admin@example.test'], array_map(fn ($k) => Setting::get($k), ['deduct_fees_automatically_enabled', 'payment_blocked_redirect_enabled',
            'pending_termination_day', 'admin_notification_email']));

        $this->save('settings.update-finance', ['deduct_day' => 32, 'admin_notification_email' => 'x'])->assertSessionHasErrors(['deduct_day', 'admin_notification_email']);
        $this->assertSame('26', Setting::get('deduct_day'));
    }

    // ── systém / uživatelé / registrace ──────────────────────────────────────

    public function test_system_uzivatele_registrace(): void
    {
        $this->save('settings.update-system', ['title' => 'Char', 'ico' => '123', 'self_registration' => '1', 'association_court' => 'KS Brno'])
            ->assertRedirect(route('settings.index', ['tab' => 'system']))->assertSessionHas('success', 'Nastavení systému bylo uloženo.');
        $this->assertSame(['Char', '123', '', '1', '0', '7200', 'KS Brno', ''], array_map(fn ($k) => Setting::get($k),
            ['title', 'ico', 'dic', 'self_registration', 'forgotten_password', 'session_expiration', 'association_court', 'association_www']));
        $this->save('settings.update-system', ['session_expiration' => 10])->assertSessionHasErrors('session_expiration');

        $this->save('settings.update-users', ['security_password_length' => 12, 'former_member_auto_device_remove' => '1'])
            ->assertRedirect(route('settings.index', ['tab' => 'users']))->assertSessionHas('success', 'Nastavení uživatelů bylo uloženo.');
        $this->assertSame(['12', '3', '1'], array_map(fn ($k) => Setting::get($k), ['security_password_length', 'security_password_level', 'former_member_auto_device_remove']));
        $this->save('settings.update-users', ['security_password_level' => 5])->assertSessionHasErrors('security_password_level');

        $this->save('settings.update-registration', ['registration_info' => '<p>Info <script>x</script></p>'])
            ->assertRedirect(route('settings.index', ['tab' => 'registrace']))->assertSessionHas('success', 'Šablony registračního PDF byly uloženy.');
        $this->assertSame(['<p>Info <script>x</script></p>', ''], [Setting::get('registration_info'), Setting::get('registration_license')]);
    }

    // ── síť ──────────────────────────────────────────────────────────────────

    public function test_sit_a_izolace_lan(): void
    {
        foreach (['lan_isolation_devices' => '', 'lan_isolation_network' => '10.133.0.0/16', 'lan_isolation_public' => '', 'lan_isolation_infra_members' => ''] as $k => $val) {
            Setting::set($k, $val);
        }
        $dhcpSubnet = (int) DB::table('subnets')->insertGetId(['name' => 'char', 'network_address' => '10.253.1.0', 'netmask' => '255.255.255.0', 'dhcp' => 1, 'dhcp_expired' => 0]);

        $base = ['lan_isolation_network' => '10.133.0.0/16', 'lan_isolation_public' => '', 'lan_isolation_devices' => '', 'lan_isolation_infra_members' => ''];
        $this->save('settings.update-network', $base + ['redirection_enabled' => '1', 'pppoe_enabled' => 'on', 'address_ranges' => '10.0.0.0/8',
            'ipv6_prefix' => '2a07:9c0', 'allowed_subnets_default_count' => '3x', 'dhcp_relay_map' => "12=ether2\r\n\r\nbez-rovnitka\n 13 = vlan5 "])
            ->assertRedirect(route('settings.index', ['tab' => 'network']))->assertSessionHas('success', 'Nastavení sítě bylo uloženo.');
        $this->assertSame(['1', '0', '0', '1', '10.0.0.0/8', '', '2a07:9c0', '', '10800', '3', "12=ether2\n13 = vlan5"], array_map(fn ($k) => Setting::get($k),
            ['redirection_enabled', 'networks_enabled', 'web_interface_require_token', 'pppoe_enabled', 'address_ranges', 'dns_servers', 'ipv6_prefix', 'ipv6_mask',
             'dhcp_lease_time', 'allowed_subnets_default_count', 'dhcp_relay_map']));
        $this->assertSame(0, (int) DB::table('subnets')->where('id', $dhcpSubnet)->value('dhcp_expired'), 'izolace beze změny → bez expirace');

        $this->save('settings.update-network', ['lan_isolation_network' => '', 'lan_isolation_public' => '10.133.37.37, 8.8.8.0/24 10.133.37.37',
            'lan_isolation_devices' => "5, 7;5\n0", 'lan_isolation_infra_members' => '2748']);
        $this->assertSame(['5' . "\n" . '7', '10.133.0.0/16', "10.133.37.37\n8.8.8.0/24", '2748'], array_map(fn ($k) => Setting::get($k),
            ['lan_isolation_devices', 'lan_isolation_network', 'lan_isolation_public', 'lan_isolation_infra_members']));
        $this->assertSame(1, (int) DB::table('subnets')->where('id', $dhcpSubnet)->value('dhcp_expired'), 'změna izolace → expirace DHCP');

        Setting::set('redirection_enabled', 1);
        $this->as()->from(route('settings.index', ['tab' => 'network']))->put(route('settings.update-network'), [
            'lan_isolation_network' => '10.133.0.1/16', 'lan_isolation_public' => 'x 10.1.1.1/40', 'lan_isolation_devices' => 'a', 'lan_isolation_infra_members' => '1,2',
        ])->assertRedirect(route('settings.index', ['tab' => 'network']))
          ->assertSessionHasErrors(['lan_isolation_network', 'lan_isolation_public', 'lan_isolation_devices'])
          ->assertSessionDoesntHaveErrors('lan_isolation_infra_members');
        $this->assertSame('1', Setting::get('redirection_enabled'), 'při chybě se nic neuloží');
    }

    public function test_regenerace_tokenu(): void
    {
        Setting::set('dhcp_api_token', \Tests\Support\Fake::secret('selftestolddhc'));
        Setting::set('web_interface_api_token', \Tests\Support\Fake::secret('selftestoldwi'));

        $this->as()->post(route('settings.regenerate-dhcp-token'))
            ->assertRedirect(route('settings.index', ['tab' => 'network']))->assertSessionHas('success', 'DHCP API token byl regenerován.');
        $this->assertSame(32, strlen(Setting::get('dhcp_api_token')));
        $this->assertNotSame(\Tests\Support\Fake::secret('selftestolddhc'), Setting::get('dhcp_api_token'));

        $before = (int) DB::table('audit_logs')->max('id');
        $this->as()->post(route('settings.regenerate-web-interface-token'))
            ->assertSessionHas('success', 'Web-interface API token byl regenerován. Nezapomeňte ho aktualizovat na igw1/igw2.');
        $this->assertSame(32, strlen(Setting::get('web_interface_api_token')));
        $audit = DB::table('audit_logs')->where('id', '>', $before)->where('auditable_type', 'config')->whereNull('auditable_id')->orderByDesc('id')->first();
        $this->assertSame(['web_interface_api_token' => '***', 'action' => 'regenerated'], json_decode($audit->new_values, true));
        $this->assertStringNotContainsString(Setting::get('web_interface_api_token'), (string) DB::table('audit_logs')->where('id', '>', $before)->pluck('new_values')->implode(' '), 'token do auditu neunikne');
    }

    // ── smlouvy ──────────────────────────────────────────────────────────────

    public function test_smlouvy_tajne_hodnoty_a_uploady(): void
    {
        Storage::fake('local');
        Setting::set('otp_pepper', \Tests\Support\Fake::secret('selftestpepper'));
        Setting::set('pdf_sign_pass', \Tests\Support\Fake::secret('selftestpass'));

        $this->save('settings.update-smlouvy', ['otp_ttl_min' => 7, 'otp_test_mode' => '1', 'pdf_sign_name' => 'Spolek', 'otp_pepper' => '',
            'pdf_sign_cert_file' => UploadedFile::fake()->create('Můj cert.P12', 2),
            'contract_email_vop_pdf_file' => UploadedFile::fake()->create('VOP 2026.pdf', 20, 'application/pdf'),
            'contract_email_pricelist_pdf' => 'storage/app/private/x.pdf'])
            ->assertRedirect(route('settings.index', ['tab' => 'smlouvy']))->assertSessionHas('success', 'Nastavení smluv bylo uloženo.');

        $this->assertSame(['7', '', '1', '0', 'Spolek', \Tests\Support\Fake::secret('selftestpepper'), \Tests\Support\Fake::secret('selftestpass'), 'storage/app/private/certs/M_j_cert.p12',
            'storage/app/private/contract-attachments/VOP_2026.pdf', 'storage/app/private/x.pdf'], array_map(fn ($k) => Setting::get($k),
            ['otp_ttl_min', 'otp_max_attempts', 'otp_test_mode', 'contract_email_attachments_enabled', 'pdf_sign_name', 'otp_pepper', 'pdf_sign_pass',
             'pdf_sign_cert', 'contract_email_vop_pdf', 'contract_email_pricelist_pdf']));
        Storage::disk('local')->assertExists(['certs/M_j_cert.p12', 'contract-attachments/VOP_2026.pdf']);

        $this->save('settings.update-smlouvy', ['otp_pepper' => \Tests\Support\Fake::secret('selftestnew'), 'pdf_sign_pass' => \Tests\Support\Fake::secret('selftestnewpas'), 'contract_email_attachments_enabled' => 'on']);
        $this->assertSame([\Tests\Support\Fake::secret('selftestnew'), \Tests\Support\Fake::secret('selftestnewpas'), '1', '', '0'], array_map(fn ($k) => Setting::get($k),
            ['otp_pepper', 'pdf_sign_pass', 'contract_email_attachments_enabled', 'pdf_sign_cert', 'otp_test_mode']));

        $this->save('settings.update-smlouvy', ['otp_ttl_min' => 61, 'pdf_sign_cert_file' => UploadedFile::fake()->create('a.exe', 1)])
            ->assertSessionHasErrors(['otp_ttl_min', 'pdf_sign_cert_file']);
    }

    // ── SledovaniTV / SMS / GPON ─────────────────────────────────────────────

    public function test_sledovanitv_ulozeni_a_sync(): void
    {
        Setting::set('sledovanitv_password', \Tests\Support\Fake::secret('selftesttvx'));
        $this->save('settings.update-sledovanitv', ['sledovanitv_enabled' => '1', 'sledovanitv_partner' => '  pvfree  ', 'sledovanitv_password' => ''])
            ->assertRedirect(route('settings.index', ['tab' => 'sledovanitv']))->assertSessionHas('success', 'Nastavení SledovaniTV bylo uloženo.');
        $this->assertSame(['1', 'pvfree', \Tests\Support\Fake::secret('selftesttvx')], array_map(fn ($k) => Setting::get($k), ['sledovanitv_enabled', 'sledovanitv_partner', 'sledovanitv_password']));
        $this->save('settings.update-sledovanitv', ['sledovanitv_password' => \Tests\Support\Fake::secret('selftesttv')]);
        $this->assertSame(['0', '', \Tests\Support\Fake::secret('selftesttv')], array_map(fn ($k) => Setting::get($k), ['sledovanitv_enabled', 'sledovanitv_partner', 'sledovanitv_password']));
        $this->save('settings.update-sledovanitv', ['sledovanitv_partner' => str_repeat('x', 51)])->assertSessionHasErrors('sledovanitv_partner');

        $svc = $this->mock(SledovaniTvService::class);
        $svc->shouldReceive('syncToMembers')->once()->andReturn(['total' => 5, 'matched' => 4, 'active' => 3, 'unmatched' => 1, 'no_partnerid' => 2]);
        $this->as()->post(route('settings.sledovanitv-sync'))->assertRedirect(route('settings.index', ['tab' => 'sledovanitv']))
            ->assertSessionHas('success', 'Sync OK: 5 total, 4 matched, 3 active, 1 unmatched, 2 bez partnerid');

        $svc = $this->mock(SledovaniTvService::class);
        $svc->shouldReceive('syncToMembers')->andThrow(new \RuntimeException('API down'));
        $this->as()->post(route('settings.sledovanitv-sync'))->assertSessionHas('error', 'Sync selhal: API down');
        $this->assertSame('CHYBA: API down', Setting::get('sledovanitv_last_sync_status'));
    }

    public function test_sms(): void
    {
        $this->save('settings.update-sms', ['sms_enabled' => '1', 'sms_sender_number' => ' 420777 ', 'sms_driver' => '5',
            'sms_driver_state3' => '2', 'sms_hostname3' => ' h ', 'sms_password3' => ' p ', 'sms_test_mode3' => '1', 'sms_driver_state5' => '9'])
            ->assertRedirect(route('settings.index', ['tab' => 'sms']))->assertSessionHas('success', 'Nastavení SMS bylo uloženo.');
        $this->assertSame(['1', '420777', '5', '2', 'h', '', 'p', '1', '1', '0'], array_map(fn ($k) => Setting::get($k),
            ['sms_enabled', 'sms_sender_number', 'sms_driver', 'sms_driver_state3', 'sms_hostname3', 'sms_user3', 'sms_password3', 'sms_test_mode3',
             'sms_driver_state5', 'sms_test_mode5']));
        $this->save('settings.update-sms', ['sms_sender_number' => str_repeat('1', 21)])->assertSessionHasErrors('sms_sender_number');

        // heslo / API klíč se do stránky neposílá; prázdné pole = ponechat
        $secret = \Tests\Support\Fake::secret('smsapi');
        Setting::set('sms_password5', $secret);
        $html = $this->as()->get(route('settings.index', ['tab' => 'sms']))->assertOk()->getContent();
        $this->assertStringNotContainsString($secret, $html);
        $this->assertStringContainsString('•••••••• (beze změny)', $html);
        $this->save('settings.update-sms', ['sms_driver' => '5', 'sms_password5' => '', 'sms_password3' => ''])->assertSessionHasNoErrors();
        $this->assertSame([$secret, 'p'], [Setting::get('sms_password5'), Setting::get('sms_password3')]);
    }

    public function test_gpon_a_olt(): void
    {
        $this->save('settings.update-gpon', ['gpon_enabled' => '1'])
            ->assertRedirect(route('settings.index', ['tab' => 'gpon']))->assertSessionHas('success', 'Nastavení GPON bylo uloženo.');
        $this->assertSame('1', Setting::get('gpon_enabled'));
        $this->save('settings.update-gpon', []);
        $this->assertSame('0', Setting::get('gpon_enabled'));

        $olt = ['name' => 'Char OLT', 'ip' => '192.0.2.77', 'snmp_user' => 'u', 'snmp_auth_pass' => \Tests\Support\Fake::secret('selftesta'), 'snmp_priv_pass' => \Tests\Support\Fake::secret('selftestp'),
            'snmp_auth_proto' => 'SHA', 'snmp_priv_proto' => 'AES', 'line_prof' => 'lp', 'service_prof' => 'sp', 'traffic_table' => 'tt', 'gpon_port' => '0/1/0',
            'base_vlan' => 100, 'vlan_map' => '{"0/1/0":101}'];
        $this->as()->post(route('settings.gpon-olts.store'), $olt)
            ->assertRedirect(route('settings.index', ['tab' => 'gpon']))->assertSessionHas('success', 'OLT byl přidán.');
        $m = GponOlt::where('ip', '192.0.2.77')->firstOrFail();
        $this->assertSame([['0/1/0' => 101], 100, \Tests\Support\Fake::secret('selftesta')], [$m->vlan_map, $m->base_vlan, $m->snmp_auth_pass]);
        $this->assertNotSame(\Tests\Support\Fake::secret('selftesta'), DB::table('gpon_olts')->where('id', $m->id)->value('snmp_auth_pass'), 'šifrováno');

        $this->as()->post(route('settings.gpon-olts.store'), $olt)->assertSessionHasErrors('ip');
        $this->as()->post(route('settings.gpon-olts.store'), ['snmp_auth_proto' => 'X'] + $olt)->assertSessionHasErrors(['ip', 'snmp_auth_proto']);

        $this->as()->put(route('settings.gpon-olts.update', $m->id), ['vlan_map' => 'neplatný json', 'name' => 'Char OLT 2'] + $olt)
            ->assertRedirect(route('settings.index', ['tab' => 'gpon']))->assertSessionHas('success', 'OLT byl aktualizován.');
        $m->refresh();
        $this->assertSame(['Char OLT 2', null], [$m->name, $m->vlan_map]);

        // hesla se do stránky neposílají; úprava s prázdnými hesly je ponechá
        $html = $this->as()->get(route('settings.index', ['tab' => 'gpon']))->assertOk()->getContent();
        $this->assertStringContainsString('Char OLT 2', $html);
        $this->assertStringNotContainsString(\Tests\Support\Fake::secret('selftesta'), $html);
        $this->assertStringNotContainsString(\Tests\Support\Fake::secret('selftestp'), $html);
        $this->as()->put(route('settings.gpon-olts.update', $m->id), ['snmp_auth_pass' => '', 'snmp_priv_pass' => '', 'name' => 'Char OLT 3'] + $olt)
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'OLT byl aktualizován.');
        $m->refresh();
        $this->assertSame(['Char OLT 3', \Tests\Support\Fake::secret('selftesta'), \Tests\Support\Fake::secret('selftestp')], [$m->name, $m->snmp_auth_pass, $m->snmp_priv_pass]);
        $this->as()->post(route('settings.gpon-olts.store'), ['ip' => '192.0.2.78', 'snmp_auth_pass' => '', 'snmp_priv_pass' => ''] + $olt)
            ->assertSessionHasErrors(['snmp_auth_pass', 'snmp_priv_pass']);
        $this->as()->put(route('settings.gpon-olts.update', 999999999), $olt)->assertNotFound();

        DB::table('onts')->insert(['serial' => 'CHAR0001', 'olt_ip' => '192.0.2.77', 'reg_status' => 'registered', 'gpon_port' => '0/1/0', 'port_num' => 0,
            'port_index' => 0, 'ont_id' => 0, 'service_port' => 0, 'vlan' => 0, 'house_no' => '', 'user_name' => '']);
        $this->as()->delete(route('settings.gpon-olts.destroy', $m->id))->assertSessionHas('error', 'OLT nelze smazat — má přiřazené ONT.');
        $this->assertNotNull(GponOlt::find($m->id));
        DB::table('onts')->where('serial', 'CHAR0001')->update(['reg_status' => 'removed']);
        $this->as()->delete(route('settings.gpon-olts.destroy', $m->id))->assertSessionHas('success', 'OLT byl smazán.');
        $this->assertNull(GponOlt::find($m->id));
        $this->as()->delete(route('settings.gpon-olts.destroy', 999999999))->assertNotFound();
    }

    // ── ACL ──────────────────────────────────────────────────────────────────

    public function test_zapisy_vyzaduji_pravo_edit(): void
    {
        $this->acl(['edit_all/Settings_Controller/finance_settings']);
        $before = Setting::get('deduct_day');
        foreach (['settings.update', 'settings.update-email', 'settings.update-finance', 'settings.update-system', 'settings.update-users',
                  'settings.update-registration', 'settings.update-network', 'settings.update-gpon', 'settings.update-smlouvy',
                  'settings.update-sledovanitv', 'settings.update-sms'] as $r) {
            $this->save($r, ['deduct_day' => 3])->assertForbidden();
        }
        foreach (['settings.regenerate-dhcp-token', 'settings.regenerate-web-interface-token', 'settings.sledovanitv-sync', 'settings.gpon-olts.store'] as $r) {
            $this->as()->post(route($r))->assertForbidden();
        }
        $this->as()->put(route('settings.gpon-olts.update', 1))->assertForbidden();
        $this->as()->delete(route('settings.gpon-olts.destroy', 1))->assertForbidden();
        $this->assertSame($before, Setting::get('deduct_day'));
    }
}
