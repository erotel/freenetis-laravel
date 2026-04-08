<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Setting;
use App\Services\AclService;
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

    public function __construct(private AclService $acl) {}

    private function can(string $action): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, self::ACL_SECTION, self::ACL_VALUE);
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

        $defaultBaId = (int) Setting::get(self::KEY_BA_DEFAULT, 0);

        // Email settings
        $emailSettings = [];
        foreach (self::EMAIL_KEYS as $key) {
            $emailSettings[$key] = Setting::get($key, '');
        }

        // BCC rules - load up to 10
        $bccRules = [];
        for ($i = 0; $i < 10; $i++) {
            $subject = Setting::get(self::EMAIL_BCC_PREFIX . $i . '_subject', '');
            $address = Setting::get(self::EMAIL_BCC_PREFIX . $i . '_address', '');
            if ($subject !== '' || $address !== '') {
                $bccRules[] = ['subject' => $subject, 'address' => $address];
            }
        }
        // Always show at least 3 empty rows for new rules
        while (count($bccRules) < 3) {
            $bccRules[] = ['subject' => '', 'address' => ''];
        }

        $activeTab = request('tab', 'banka');

        return view('settings.index', compact(
            'bankAccounts', 'memberTypes', 'routing', 'defaultBaId',
            'emailSettings', 'bccRules', 'activeTab'
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
            Setting::set(self::EMAIL_BCC_PREFIX . $i . '_subject', '');
            Setting::set(self::EMAIL_BCC_PREFIX . $i . '_address', '');
        }
        // Save new rules (skip fully empty pairs)
        $subjects  = $request->input('bcc_subject', []);
        $addresses = $request->input('bcc_address', []);
        $idx = 0;
        foreach ($subjects as $i => $subject) {
            $address = $addresses[$i] ?? '';
            if (trim($subject) !== '' || trim($address) !== '') {
                Setting::set(self::EMAIL_BCC_PREFIX . $idx . '_subject', trim($subject));
                Setting::set(self::EMAIL_BCC_PREFIX . $idx . '_address', trim($address));
                $idx++;
            }
        }

        return redirect()->route('settings.index', ['tab' => 'email'])
            ->with('success', 'Nastavení emailu bylo uloženo.');
    }
}
