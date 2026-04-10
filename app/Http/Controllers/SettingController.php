<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Http\Request;

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
    ];

    public const USERS_KEYS = [
        'security_password_length', 'security_password_level', 'former_member_auto_device_remove',
    ];

    public const NETWORK_KEYS = [
        'redirection_enabled', 'networks_enabled', 'address_ranges', 'dns_servers',
    ];

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index()
    {
        abort_unless($this->can('view_all'), 403);

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

        return view('settings.index', compact(
            'bankAccounts', 'memberTypes', 'routing', 'defaultBaId',
            'emailSettings', 'bccRules', 'messages', 'activeTab',
            'pohodaEmail', 'financeSettings', 'feesForSelect',
            'systemSettings', 'usersSettings', 'networkSettings'
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
        return redirect()->route('settings.index', ['tab' => 'network'])
            ->with('success', 'Nastavení sítě bylo uloženo.');
    }
}
