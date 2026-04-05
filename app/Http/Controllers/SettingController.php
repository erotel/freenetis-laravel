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
                'label'      => $label,
                'key'        => $key,
                'bank_account_id' => (int) Setting::get($key, 0),
            ];
        }

        $defaultBaId = (int) Setting::get(self::KEY_BA_DEFAULT, 0);

        return view('settings.index', compact('bankAccounts', 'memberTypes', 'routing', 'defaultBaId'));
    }

    public function update(Request $request)
    {
        abort_unless($this->can('edit_all'), 403);

        $memberTypes = [2, 90];

        foreach ($memberTypes as $type) {
            $key = sprintf(self::KEY_BA_MEMBER_TYPE, $type);
            $val = (int) $request->input("routing_{$type}", 0);
            Setting::set($key, $val);
        }

        $defaultBaId = (int) $request->input('default_bank_account_id', 0);
        Setting::set(self::KEY_BA_DEFAULT, $defaultBaId);

        return redirect()->route('settings.index')->with('success', 'Nastavení bylo uloženo.');
    }
}
