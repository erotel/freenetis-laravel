<?php

namespace App\Http\Controllers;

use App\Domains\Access\Repositories\GponOltRepository;
use App\Domains\Access\Settings\ApiTokens;
use App\Domains\Access\Settings\GponSettings;
use App\Domains\Billing\Settings\BankRoutingSettings;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Contracts\Settings\ContractSettings;
use App\Domains\Members\Settings\SledovaniTvSettings;
use App\Domains\Members\Settings\SystemSettings;
use App\Domains\Network\Settings\NetworkSettings;
use App\Domains\Notifications\Settings\EmailSettings;
use App\Domains\Notifications\Settings\SmsSettings;
use App\Http\Requests\Settings\SaveContractSettingsRequest;
use App\Http\Requests\Settings\SaveEmailSettingsRequest;
use App\Http\Requests\Settings\SaveFinanceSettingsRequest;
use App\Http\Requests\Settings\SaveGponOltRequest;
use App\Http\Requests\Settings\SaveSledovaniTvSettingsRequest;
use App\Http\Requests\Settings\SaveSmsSettingsRequest;
use App\Http\Requests\Settings\SaveSystemSettingsRequest;
use App\Http\Requests\Settings\SaveUsersSettingsRequest;
use App\Http\Requests\Settings\SettingsRequest;

/**
 * Nastavení systému (záložky). Čtení i zápis každé záložky jsou v doméně,
 * které se týká (app/Domains/<Doména>/Settings); zápis hlídá SettingsRequest
 * (ACL edit_all), zobrazení routa (acl:view_all).
 */
class SettingController extends Controller
{
    // Klíče záložek — zdrojem pravdy jsou doménové třídy.
    public const KEY_BA_MEMBER_TYPE = BankRoutingSettings::KEY_MEMBER_TYPE;
    public const KEY_BA_DEFAULT     = BankRoutingSettings::KEY_DEFAULT;
    public const EMAIL_KEYS         = EmailSettings::KEYS;
    public const EMAIL_BCC_PREFIX   = EmailSettings::BCC_PREFIX;
    public const FINANCE_KEYS       = FinanceSettings::KEYS;
    public const SYSTEM_KEYS        = SystemSettings::SYSTEM_KEYS;
    public const REGISTRATION_KEYS  = SystemSettings::REGISTRATION_KEYS;
    public const USERS_KEYS         = SystemSettings::USERS_KEYS;
    public const NETWORK_KEYS       = NetworkSettings::KEYS;
    public const GPON_KEYS          = GponSettings::KEYS;
    public const SMLOUVY_KEYS       = ContractSettings::KEYS;
    public const SLEDOVANITV_KEYS   = SledovaniTvSettings::KEYS;
    public const SMS_DRIVERS        = SmsSettings::DRIVERS;

    private function back(string $tab, string $message, string $flash = 'success')
    {
        return redirect()->route('settings.index', ['tab' => $tab])->with($flash, $message);
    }

    public function index(
        BankRoutingSettings $bank, EmailSettings $email, FinanceSettings $finance, SystemSettings $system,
        NetworkSettings $network, ApiTokens $tokens, SmsSettings $sms, GponSettings $gpon, GponOltRepository $olts,
        ContractSettings $contracts, SledovaniTvSettings $tv,
    ) {
        $apiTokens = $tokens->ensure();
        // Celé tokeny (zadávají se na brány/routery) jen pro toho, kdo smí
        // nastavení měnit; kdo smí jen číst, uvidí začátek.
        if (!$this->aclCheck('edit_all', 'Settings_Controller', 'finance_settings')) {
            $apiTokens = array_map(fn ($t) => $t ? substr((string) $t, 0, 4) . '••••••••' : $t, $apiTokens);
        }

        return view('settings.index', [
            'bankAccounts'         => $bank->associationAccounts(),
            'memberTypes'          => BankRoutingSettings::MEMBER_TYPES,
            'routing'              => $bank->routing(),
            'defaultBaId'          => $bank->defaultAccountId(),
            'pohodaEmail'          => $bank->accountantEmail(),
            'emailSettings'        => $email->values(),
            'bccRules'             => $email->bccRules(),
            'messages'             => $email->messages(),
            'activeTab'            => request('tab', 'banka'),
            'financeSettings'      => $finance->values(),
            'feesForSelect'        => $finance->regularFees(),
            'speedClassesFinance'  => $finance->tariffs(),
            'systemSettings'       => $system->values(SystemSettings::SYSTEM_KEYS),
            'usersSettings'        => $system->values(SystemSettings::USERS_KEYS),
            'registrationSettings' => $system->values(SystemSettings::REGISTRATION_KEYS),
            'networkSettings'      => $network->values(),
            'dhcpApiToken'         => $apiTokens['dhcp'],
            'webInterfaceApiToken' => $apiTokens['web_interface'],
            'smsSettings'          => $sms->values(),
            'smsDriverSettings'    => $sms->driverValues(),
            'gponSettings'         => $gpon->values(),
            'gponOlts'             => $olts->allWithOntCounts(),
            'smlouvySettings'      => $contracts->values(),
            'sledovanitvSettings'  => $tv->values(),
        ]);
    }

    /** Banka: směrování plateb podle typu člena, výchozí účet, e-mail účetní. */
    public function update(SettingsRequest $request, BankRoutingSettings $bank)
    {
        $routing = [];
        foreach (array_keys(BankRoutingSettings::MEMBER_TYPES) as $type) {
            $routing[$type] = [(int) $request->input("routing_{$type}", 0), $request->has("payment_purpose_{$type}")];
        }
        $bank->save($routing, (int) $request->input('default_bank_account_id', 0), $request->input('pohoda_accountant_email', ''));

        return redirect()->route('settings.index')->with('success', 'Nastavení bylo uloženo.');
    }

    public function updateEmail(SaveEmailSettingsRequest $request, EmailSettings $email)
    {
        $values = [];
        foreach (EmailSettings::KEYS as $key) {
            $values[$key] = $request->input($key, '');
        }
        // nahraný soubor má přednost před textovým polem
        if ($request->hasFile('registration_summary_pdf_file') && $request->file('registration_summary_pdf_file')->isValid()) {
            $values['registration_summary_pdf'] = $email->storeSummaryPdf($request->file('registration_summary_pdf_file'));
        }
        $email->save($values);
        $email->saveBccRules($request->input('bcc_message_id', []), $request->input('bcc_subject_prefix', []), $request->input('bcc_address', []));

        return $this->back('email', 'Nastavení emailu bylo uloženo.');
    }

    public function updateFinance(SaveFinanceSettingsRequest $request, FinanceSettings $finance)
    {
        $v = $request->validated();
        $finance->save(
            $request->boolean('deduct_fees_automatically_enabled'),
            $v['deduct_day'],
            $request->boolean('finance_enabled'),
            $request->input('default_fee_member_type_2', ''),
            $request->input('default_fee_member_type_90', ''),
            $request->boolean('payment_blocked_redirect_enabled'),
            $v['pending_termination_day'] ?? null,
            $v['admin_notification_email'] ?? null,
        );
        $finance->saveTariffPrices((array) $request->input('speed_price', []));

        return $this->back('finance', 'Nastavení financí bylo uloženo.');
    }

    public function updateSystem(SaveSystemSettingsRequest $request, SystemSettings $system)
    {
        $texts = ['session_expiration' => $request->input('session_expiration', 7200)];
        foreach (['title', 'ico', 'dic', 'association_www', 'association_email', 'association_phone', 'association_court', 'association_court_ref'] as $key) {
            $texts[$key] = $request->input($key, '');
        }
        $system->saveSystem($texts, $request->boolean('self_registration'), $request->boolean('forgotten_password'));

        return $this->back('system', 'Nastavení systému bylo uloženo.');
    }

    public function updateUsers(SaveUsersSettingsRequest $request, SystemSettings $system)
    {
        $system->saveUsers(
            $request->input('security_password_length', 8),
            $request->input('security_password_level', 3),
            $request->boolean('former_member_auto_device_remove'),
        );

        return $this->back('users', 'Nastavení uživatelů bylo uloženo.');
    }

    public function updateRegistration(SettingsRequest $request, SystemSettings $system)
    {
        $system->saveRegistration($request->only(SystemSettings::REGISTRATION_KEYS));

        return $this->back('registrace', 'Šablony registračního PDF byly uloženy.');
    }

    public function updateNetwork(SettingsRequest $request, NetworkSettings $network)
    {
        // izolace se validuje dřív, než se cokoliv uloží
        [$lan, $errors] = $network->normalizeLanIsolation($request->only(
            'lan_isolation_network', 'lan_isolation_public', 'lan_isolation_devices', 'lan_isolation_infra_members'
        ));
        if ($errors) {
            return redirect()->route('settings.index', ['tab' => 'network'])->withInput()->withErrors($errors);
        }
        $network->saveLanIsolation($lan);

        $flags = [];
        foreach (['redirection_enabled', 'networks_enabled', 'web_interface_require_token', 'pppoe_enabled'] as $key) {
            $flags[$key] = $request->boolean($key);
        }
        $network->save($flags, [
            'address_ranges'                  => $request->input('address_ranges', ''),
            'dns_servers'                     => $request->input('dns_servers', ''),
            'ipv6_prefix'                     => $request->input('ipv6_prefix', ''),
            'ipv6_mask'                       => $request->input('ipv6_mask', ''),
            'connection_request_notify_email' => $request->input('connection_request_notify_email', ''),
            'dhcp_lease_time'                 => $request->input('dhcp_lease_time', '10800'),
            'allowed_subnets_default_count'   => $request->input('allowed_subnets_default_count', 1),
            'dhcp_relay_map'                  => $request->input('dhcp_relay_map', ''),
        ]);

        return $this->back('network', 'Nastavení sítě bylo uloženo.');
    }

    public function regenerateDhcpToken(SettingsRequest $request, ApiTokens $tokens)
    {
        $tokens->regenerateDhcp();
        return $this->back('network', 'DHCP API token byl regenerován.');
    }

    public function regenerateWebInterfaceToken(SettingsRequest $request, ApiTokens $tokens)
    {
        $tokens->regenerateWebInterface();
        return $this->back('network', 'Web-interface API token byl regenerován. Nezapomeňte ho aktualizovat na igw1/igw2.');
    }

    public function updateGpon(SettingsRequest $request, GponSettings $gpon)
    {
        $gpon->save($request->boolean('gpon_enabled'));
        return $this->back('gpon', 'Nastavení GPON bylo uloženo.');
    }

    public function updateSmlouvy(SaveContractSettingsRequest $request, ContractSettings $contracts)
    {
        $plain = [];
        foreach (ContractSettings::PLAIN_KEYS as $key) {
            $plain[$key] = $request->input($key, '');
        }
        // nahraný soubor má přednost před textovým polem s cestou
        foreach (ContractSettings::UPLOADS as $field => $meta) {
            if ($request->hasFile($field) && $request->file($field)->isValid()) {
                $plain[$meta['target']] = $contracts->storeUpload($field, $request->file($field));
            }
        }
        $secrets = [];
        foreach (ContractSettings::SECRET_KEYS as $key) {
            $secrets[$key] = (string) $request->input($key, '');
        }
        $contracts->save($plain, $secrets, $request->boolean('otp_test_mode'), $request->boolean('contract_email_attachments_enabled'));

        return $this->back('smlouvy', 'Nastavení smluv bylo uloženo.');
    }

    public function updateSledovaniTv(SaveSledovaniTvSettingsRequest $request, SledovaniTvSettings $tv)
    {
        $tv->save($request->boolean('sledovanitv_enabled'), (string) $request->input('sledovanitv_partner', ''), (string) $request->input('sledovanitv_password', ''));
        return $this->back('sledovanitv', 'Nastavení SledovaniTV bylo uloženo.');
    }

    public function syncSledovaniTv(SettingsRequest $request, SledovaniTvSettings $tv)
    {
        try {
            $s = $tv->sync();
        } catch (\Throwable $e) {
            return $this->back('sledovanitv', 'Sync selhal: ' . $e->getMessage(), 'error');
        }
        return $this->back('sledovanitv', sprintf('Sync OK: %d total, %d matched, %d active, %d unmatched, %d bez partnerid',
            $s['total'], $s['matched'], $s['active'], $s['unmatched'], $s['no_partnerid']));
    }

    public function updateSms(SaveSmsSettingsRequest $request, SmsSettings $sms)
    {
        $drivers = [];
        foreach (array_keys(SmsSettings::DRIVERS) as $id) {
            $drivers[$id] = [
                'state'     => (int) $request->input('sms_driver_state' . $id, 1),
                'hostname'  => (string) $request->input('sms_hostname' . $id, ''),
                'user'      => (string) $request->input('sms_user' . $id, ''),
                'password'  => (string) $request->input('sms_password' . $id, ''),
                'test_mode' => $request->boolean('sms_test_mode' . $id),
            ];
        }
        $sms->save($request->boolean('sms_enabled'), (string) $request->input('sms_sender_number', ''), (string) $request->input('sms_driver', ''), $drivers);

        return $this->back('sms', 'Nastavení SMS bylo uloženo.');
    }

    // ── GPON OLT ─────────────────────────────────────────────────────────────

    public function storeGponOlt(SaveGponOltRequest $request, GponOltRepository $olts)
    {
        $olts->create($request->oltData());
        return $this->back('gpon', 'OLT byl přidán.');
    }

    public function updateGponOlt(SaveGponOltRequest $request, int $id, GponOltRepository $olts)
    {
        $olts->update($id, $request->oltData());
        return $this->back('gpon', 'OLT byl aktualizován.');
    }

    public function destroyGponOlt(SettingsRequest $request, int $id, GponOltRepository $olts)
    {
        return $olts->deleteUnlessUsed($id)
            ? $this->back('gpon', 'OLT byl smazán.')
            : $this->back('gpon', 'OLT nelze smazat — má přiřazené ONT.', 'error');
    }
}
