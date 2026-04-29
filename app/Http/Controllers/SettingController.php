<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SettingController extends Controller
{
    private const ACL_SECTION = 'Settings_Controller';
    private const ACL_VALUE   = 'finance_settings';

    // Config keys managed on the bank-routing settings sub-page
    public const KEY_BA_MEMBER_TYPE   = 'bank_account_member_type_%d';   // sprintf with member type int
    public const KEY_BA_DEFAULT       = 'bank_account_default_import';    // fallback when no type rule

    // Email config keys
    public const EMAIL_KEYS = [
        'email_enabled', 'email_driver', 'email_hostname', 'email_port',
        'email_username', 'email_password', 'email_encryption', 'email_default_email',
        'registration_summary_enabled', 'registration_summary_pdf',
    ];

    // BCC rules key prefix: email_bcc_rule_{n}_subject, email_bcc_rule_{n}_address
    public const EMAIL_BCC_PREFIX = 'email_bcc_rule_';

    // Finance config keys
    public const FINANCE_KEYS = [
        'deduct_fees_automatically_enabled',
        'deduct_day',
        'finance_enabled',
        'default_fee_member_type_2',   // zákazník (type 2) - default fee_id
        'default_fee_member_type_90',  // člen (type 90) - default fee_id
    ];

    public const SYSTEM_KEYS = [
        'title', 'ico', 'dic', 'self_registration', 'forgotten_password', 'session_expiration',
        'association_www', 'association_email', 'association_phone',
        'association_court', 'association_court_ref',
    ];

    public const USERS_KEYS = [
        'security_password_length', 'security_password_level', 'former_member_auto_device_remove',
    ];

    public const NETWORK_KEYS = [
        'redirection_enabled', 'networks_enabled', 'address_ranges', 'dns_servers',
        'ipv6_prefix', 'ipv6_mask', 'connection_request_notify_email',
        'dhcp_lease_time',
    ];

    public const GPON_KEYS = [
        'gpon_enabled',
    ];

    // SMS drivers: id → config
    // has_hostname: true = admin can override hostname (Klikniavolej only)
    // has_test_mode: true = show test mode toggle (Klikniavolej only)
    public const SMS_DRIVERS = [
        3 => ['name' => 'KlikniaVolej.cz',       'has_hostname' => true,  'has_test_mode' => true],
        5 => ['name' => 'SmsManager JSON API v2', 'has_hostname' => false, 'has_test_mode' => false],
    ];

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index()
    {
        $bankAccounts = BankAccount::orderBy('name')->get(['id', 'name', 'account_nr', 'bank_nr']);

        // Member type → bank account routing rules
        // Kohana used types: 1=admin, 2=zákazník, 3=..., 15=..., 90=člen
        $memberTypes = [
            2  => 'Zákazník (typ 2)',
            90 => 'Člen (typ 90)',
        ];

        $routing = [];
        foreach ($memberTypes as $type => $label) {
            $key = sprintf(self::KEY_BA_MEMBER_TYPE, $type);
            $routing[$type] = [
                'label'           => $label,
                'key'             => $key,
                'bank_account_id' => (int) Setting::get($key, 0),
            ];
        }

        foreach ($routing as $type => &$rule) {
            $ba = $rule['bank_account_id']
                ? BankAccount::find($rule['bank_account_id'])
                : null;
            $rule['payment_purpose'] = $ba ? (int) $ba->payment_purpose : 0;
        }
        unset($rule);

        $defaultBaId  = (int) Setting::get(self::KEY_BA_DEFAULT, 0);
        $pohodaEmail  = Setting::get('pohoda_accountant_email', '');

        // Email settings
        $emailSettings = [];
        foreach (self::EMAIL_KEYS as $key) {
            $emailSettings[$key] = Setting::get($key, '');
        }

        // BCC rules - load up to 10
        $bccRules = [];
        for ($i = 0; $i < 10; $i++) {
            $messageId     = Setting::get(self::EMAIL_BCC_PREFIX . $i . '_message_id', '');
            $subjectPrefix = Setting::get(self::EMAIL_BCC_PREFIX . $i . '_subject_prefix', '');
            $address       = Setting::get(self::EMAIL_BCC_PREFIX . $i . '_address', '');
            if ($messageId !== '' || $subjectPrefix !== '' || $address !== '') {
                $bccRules[] = [
                    'message_id'     => $messageId,
                    'subject_prefix' => $subjectPrefix,
                    'address'        => $address,
                ];
            }
        }
        // Always show at least 3 empty rows for new rules
        while (count($bccRules) < 3) {
            $bccRules[] = ['message_id' => '', 'subject_prefix' => '', 'address' => ''];
        }

        $messages  = Message::orderBy('name')->get(['id', 'name', 'type']);
        $activeTab = request('tab', 'banka');

        // Finance settings
        $financeSettings = [];
        foreach (self::FINANCE_KEYS as $key) {
            $financeSettings[$key] = Setting::get($key, '');
        }

        // Fees for dropdown (only regular member fee type)
        $feesForSelect = \Illuminate\Support\Facades\DB::table('fees as f')
            ->join('enum_types as et', 'et.id', '=', 'f.type_id')
            ->whereRaw("LOWER(et.value) = 'regular member fee'")
            ->orderBy('f.name')
            ->get(['f.id', 'f.name', 'f.fee', 'f.from', 'f.to']);

        $systemSettings = [];
        foreach (self::SYSTEM_KEYS as $key) {
            $systemSettings[$key] = Setting::get($key, '');
        }

        $usersSettings = [];
        foreach (self::USERS_KEYS as $key) {
            $usersSettings[$key] = Setting::get($key, '');
        }

        $networkSettings = [];
        foreach (self::NETWORK_KEYS as $key) {
            $networkSettings[$key] = Setting::get($key, '');
        }

        if (!Setting::get('dhcp_api_token')) {
            Setting::set('dhcp_api_token', Str::random(32));
        }
        $dhcpApiToken = Setting::get('dhcp_api_token');

        // SMS settings
        $smsSettings = [
            'sms_enabled'       => Setting::get('sms_enabled', '0'),
            'sms_sender_number' => Setting::get('sms_sender_number', ''),
            'sms_driver'        => Setting::get('sms_driver', ''),
        ];
        $smsDriverSettings = [];
        foreach (array_keys(self::SMS_DRIVERS) as $dId) {
            $smsDriverSettings[$dId] = [
                'state'     => Setting::get('sms_driver_state' . $dId, '1'),
                'hostname'  => Setting::get('sms_hostname'     . $dId, ''),
                'user'      => Setting::get('sms_user'         . $dId, ''),
                'password'  => Setting::get('sms_password'     . $dId, ''),
                'test_mode' => Setting::get('sms_test_mode'    . $dId, '0'),
            ];
        }

        // GPON settings
        $gponSettings = [];
        foreach (self::GPON_KEYS as $key) {
            $gponSettings[$key] = Setting::get($key, '');
        }
        $gponOlts = \App\Models\GponOlt::withCount('onts')->get();

        return view('settings.index', compact(
            'bankAccounts', 'memberTypes', 'routing', 'defaultBaId',
            'emailSettings', 'bccRules', 'messages', 'activeTab',
            'pohodaEmail', 'financeSettings', 'feesForSelect',
            'systemSettings', 'usersSettings', 'networkSettings',
            'smsSettings', 'smsDriverSettings', 'gponSettings', 'gponOlts',
            'dhcpApiToken'
        ));
    }

    public function update(Request $request)
    {
        abort_unless($this->can('edit_all'), 403);

        $memberTypes = [2, 90];

        foreach ($memberTypes as $type) {
            $key = sprintf(self::KEY_BA_MEMBER_TYPE, $type);
            $val = (int) $request->input("routing_{$type}", 0);
            Setting::set($key, $val);

            $ppVal = $request->has("payment_purpose_{$type}") ? 1 : 0;
            if ($val > 0) {
                BankAccount::where('id', $val)->update(['payment_purpose' => $ppVal]);
            }
        }

        $defaultBaId = (int) $request->input('default_bank_account_id', 0);
        Setting::set(self::KEY_BA_DEFAULT, $defaultBaId);

        Setting::set('pohoda_accountant_email', $request->input('pohoda_accountant_email', ''));

        return redirect()->route('settings.index')->with('success', 'Nastavení bylo uloženo.');
    }

    public function updateEmail(Request $request)
    {
        abort_unless($this->can('edit_all'), 403);

        foreach (self::EMAIL_KEYS as $key) {
            Setting::set($key, $request->input($key, ''));
        }

        // Clear old BCC rules first
        for ($i = 0; $i < 10; $i++) {
            Setting::set(self::EMAIL_BCC_PREFIX . $i . '_message_id', '');
            Setting::set(self::EMAIL_BCC_PREFIX . $i . '_subject_prefix', '');
            Setting::set(self::EMAIL_BCC_PREFIX . $i . '_address', '');
        }
        // Save new rules (skip fully empty pairs)
        $messageIds      = $request->input('bcc_message_id', []);
        $subjectPrefixes = $request->input('bcc_subject_prefix', []);
        $addresses       = $request->input('bcc_address', []);
        $idx = 0;
        foreach ($addresses as $i => $address) {
            $address       = trim($address);
            $subjectPrefix = trim($subjectPrefixes[$i] ?? '');
            $messageId     = trim($messageIds[$i] ?? '');
            if ($address !== '' || $subjectPrefix !== '') {
                Setting::set(self::EMAIL_BCC_PREFIX . $idx . '_message_id',     $messageId);
                Setting::set(self::EMAIL_BCC_PREFIX . $idx . '_subject_prefix', $subjectPrefix);
                Setting::set(self::EMAIL_BCC_PREFIX . $idx . '_address',        $address);
                $idx++;
            }
        }

        return redirect()->route('settings.index', ['tab' => 'email'])
            ->with('success', 'Nastavení emailu bylo uloženo.');
    }

    public function updateFinance(Request $request)
    {
        abort_unless($this->can('edit_all'), 403);

        $validated = $request->validate([
            'deduct_day' => 'required|integer|min:1|max:31',
        ]);

        Setting::set('deduct_fees_automatically_enabled', $request->boolean('deduct_fees_automatically_enabled') ? 1 : 0);
        Setting::set('deduct_day', $validated['deduct_day']);
        Setting::set('finance_enabled', $request->boolean('finance_enabled') ? 1 : 0);
        Setting::set('default_fee_member_type_2',  $request->input('default_fee_member_type_2', ''));
        Setting::set('default_fee_member_type_90', $request->input('default_fee_member_type_90', ''));

        return redirect()->route('settings.index', ['tab' => 'finance'])
            ->with('success', 'Nastavení financí bylo uloženo.');
    }

    public function updateSystem(Request $request)
    {
        abort_unless($this->can('edit_all'), 403);
        $request->validate([
            'title'              => 'nullable|string|max:100',
            'ico'                => 'nullable|string|max:20',
            'dic'                => 'nullable|string|max:20',
            'session_expiration' => 'nullable|integer|min:300',
        ]);
        Setting::set('title',              $request->input('title', ''));
        Setting::set('ico',                $request->input('ico', ''));
        Setting::set('dic',                $request->input('dic', ''));
        Setting::set('self_registration',  $request->boolean('self_registration') ? 1 : 0);
        Setting::set('forgotten_password', $request->boolean('forgotten_password') ? 1 : 0);
        Setting::set('session_expiration', $request->input('session_expiration', 7200));
        Setting::set('association_www',      $request->input('association_www', ''));
        Setting::set('association_email',    $request->input('association_email', ''));
        Setting::set('association_phone',    $request->input('association_phone', ''));
        Setting::set('association_court',    $request->input('association_court', ''));
        Setting::set('association_court_ref',$request->input('association_court_ref', ''));
        return redirect()->route('settings.index', ['tab' => 'system'])
            ->with('success', 'Nastavení systému bylo uloženo.');
    }

    public function updateUsers(Request $request)
    {
        abort_unless($this->can('edit_all'), 403);
        $request->validate([
            'security_password_length' => 'nullable|integer|min:4|max:32',
            'security_password_level'  => 'nullable|integer|in:1,2,3,4',
        ]);
        Setting::set('security_password_length',         $request->input('security_password_length', 8));
        Setting::set('security_password_level',          $request->input('security_password_level', 3));
        Setting::set('former_member_auto_device_remove', $request->boolean('former_member_auto_device_remove') ? 1 : 0);
        return redirect()->route('settings.index', ['tab' => 'users'])
            ->with('success', 'Nastavení uživatelů bylo uloženo.');
    }

    public function updateNetwork(Request $request)
    {
        abort_unless($this->can('edit_all'), 403);
        Setting::set('redirection_enabled', $request->boolean('redirection_enabled') ? 1 : 0);
        Setting::set('networks_enabled',    $request->boolean('networks_enabled') ? 1 : 0);
        Setting::set('address_ranges',      $request->input('address_ranges', ''));
        Setting::set('dns_servers',         $request->input('dns_servers', ''));
        Setting::set('ipv6_prefix',                    $request->input('ipv6_prefix', ''));
        Setting::set('ipv6_mask',                      $request->input('ipv6_mask', ''));
        Setting::set('connection_request_notify_email', $request->input('connection_request_notify_email', ''));
        Setting::set('dhcp_lease_time', $request->input('dhcp_lease_time', '10800'));
        return redirect()->route('settings.index', ['tab' => 'network'])
            ->with('success', 'Nastavení sítě bylo uloženo.');
    }

    public function regenerateDhcpToken()
    {
        abort_unless($this->can('edit_all'), 403);
        Setting::set('dhcp_api_token', Str::random(32));
        return redirect()->route('settings.index', ['tab' => 'network'])
            ->with('success', 'DHCP API token byl regenerován.');
    }

    public function updateGpon(Request $request)
    {
        abort_unless($this->can('edit_all'), 403);

        Setting::set('gpon_enabled', $request->boolean('gpon_enabled') ? 1 : 0);

        return redirect()->route('settings.index', ['tab' => 'gpon'])
            ->with('success', 'Nastavení GPON bylo uloženo.');
    }

    public function updateSms(Request $request)
    {
        abort_unless($this->can('edit_all'), 403);

        $request->validate([
            'sms_sender_number' => 'nullable|string|max:20',
        ]);

        Setting::set('sms_enabled',       $request->boolean('sms_enabled') ? 1 : 0);
        Setting::set('sms_sender_number', trim((string) $request->input('sms_sender_number', '')));
        Setting::set('sms_driver',        (string) $request->input('sms_driver', ''));

        foreach (array_keys(self::SMS_DRIVERS) as $dId) {
            $state = (int) $request->input('sms_driver_state' . $dId, 1);
            Setting::set('sms_driver_state' . $dId, in_array($state, [1, 2]) ? $state : 1);
            Setting::set('sms_hostname'     . $dId, trim((string) $request->input('sms_hostname'  . $dId, '')));
            Setting::set('sms_user'         . $dId, trim((string) $request->input('sms_user'      . $dId, '')));
            Setting::set('sms_password'     . $dId, trim((string) $request->input('sms_password'  . $dId, '')));
            Setting::set('sms_test_mode'    . $dId, $request->boolean('sms_test_mode' . $dId) ? 1 : 0);
        }

        return redirect()->route('settings.index', ['tab' => 'sms'])
            ->with('success', 'Nastavení SMS bylo uloženo.');
    }

    public function storeGponOlt(Request $request)
    {
        abort_unless($this->can('edit_all'), 403);

        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'ip'              => 'required|ip|unique:gpon_olts,ip',
            'snmp_user'       => 'required|string|max:100',
            'snmp_auth_pass'  => 'required|string|max:100',
            'snmp_priv_pass'  => 'required|string|max:100',
            'snmp_auth_proto' => 'required|in:SHA,MD5',
            'snmp_priv_proto' => 'required|in:AES,DES',
            'line_prof'       => 'required|string|max:100',
            'service_prof'    => 'required|string|max:100',
            'traffic_table'   => 'required|string|max:100',
            'gpon_port'       => 'required|string|max:20',
            'base_vlan'       => 'nullable|integer|min:1|max:4094',
            'port_count'      => 'nullable|integer|min:1',
            'vlan_map'        => 'nullable|string',
            'geocode_city'    => 'nullable|string|max:100',
        ]);

        if (!empty($data['vlan_map'])) {
            $decoded = json_decode($data['vlan_map'], true);
            $data['vlan_map'] = is_array($decoded) ? $decoded : null;
        } else {
            $data['vlan_map'] = null;
        }

        \App\Models\GponOlt::create($data);

        return redirect()->route('settings.index', ['tab' => 'gpon'])
            ->with('success', 'OLT byl přidán.');
    }

    public function updateGponOlt(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $olt  = \App\Models\GponOlt::findOrFail($id);
        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'ip'              => 'required|ip|unique:gpon_olts,ip,' . $id,
            'snmp_user'       => 'required|string|max:100',
            'snmp_auth_pass'  => 'required|string|max:100',
            'snmp_priv_pass'  => 'required|string|max:100',
            'snmp_auth_proto' => 'required|in:SHA,MD5',
            'snmp_priv_proto' => 'required|in:AES,DES',
            'line_prof'       => 'required|string|max:100',
            'service_prof'    => 'required|string|max:100',
            'traffic_table'   => 'required|string|max:100',
            'gpon_port'       => 'required|string|max:20',
            'base_vlan'       => 'nullable|integer|min:1|max:4094',
            'port_count'      => 'nullable|integer|min:1',
            'vlan_map'        => 'nullable|string',
            'geocode_city'    => 'nullable|string|max:100',
        ]);

        if (!empty($data['vlan_map'])) {
            $decoded = json_decode($data['vlan_map'], true);
            $data['vlan_map'] = is_array($decoded) ? $decoded : null;
        } else {
            $data['vlan_map'] = null;
        }

        $olt->update($data);

        return redirect()->route('settings.index', ['tab' => 'gpon'])
            ->with('success', 'OLT byl aktualizován.');
    }

    public function destroyGponOlt(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $olt = \App\Models\GponOlt::findOrFail($id);

        if ($olt->onts()->whereIn('reg_status', ['registered', 'new'])->exists()) {
            return redirect()->route('settings.index', ['tab' => 'gpon'])
                ->with('error', 'OLT nelze smazat — má přiřazené ONT.');
        }

        $olt->delete();

        return redirect()->route('settings.index', ['tab' => 'gpon'])
            ->with('success', 'OLT byl smazán.');
    }
}
