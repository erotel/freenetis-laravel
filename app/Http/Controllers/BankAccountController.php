<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Setting;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';
    private const ACL_VALUE   = 'bank_accounts';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $query = BankAccount::with('member')->orderBy('id');

        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like, $q) {
                $w->where('name', 'like', $like)
                  ->orWhere('account_nr', 'like', $like)
                  ->orWhere('bank_nr',    'like', $like)
                  ->orWhere('IBAN',       'like', $like)
                  ->orWhereHas('member', fn ($m) => $m->where('name', 'like', $like));
                if (ctype_digit($q)) {
                    $w->orWhere('id', (int) $q)
                      ->orWhere('member_id', (int) $q);
                }
            });
        }

        $all = $query->get();

        $associationAccounts = $all->where('member_id', 1)->values();
        $memberAccounts      = $all->where('member_id', '!=', 1)->values();

        $canManageAutoDown = $this->aclCheck('view_all', self::ACL_SECTION, 'bank_account_auto_down_config');

        // For association accounts: check which have a FIO token or auto-download rules
        $autoDownFlags = [];
        if ($canManageAutoDown) {
            foreach ($associationAccounts as $account) {
                $hasRules = \Illuminate\Support\Facades\DB::table('bank_accounts_automatical_downloads')
                    ->where('bank_account_id', $account->id)->exists();
                $autoDownFlags[$account->id] = $hasRules;
            }
        }

        return view('bank_accounts.index', compact(
            'associationAccounts', 'memberAccounts', 'canManageAutoDown', 'autoDownFlags', 'q'
        ));
    }

    public function show(int $id)
    {
        abort_unless($this->can('view_all'), 403);

        $account = BankAccount::with('member')->findOrFail($id);

        $canViewTransfers    = $this->aclCheck('view_all', self::ACL_SECTION, 'bank_transfers');
        $canViewStatements   = $this->aclCheck('view_all', self::ACL_SECTION, 'bank_statements');
        $canEdit             = $this->can('edit_all');
        $hasFioToken         = !empty(Setting::get('fio_api_token_bank_account_' . $account->id));
        $canManageAutoDown   = $this->aclCheck('view_all', self::ACL_SECTION, 'bank_account_auto_down_config');

        return view('bank_accounts.show', compact('account', 'canViewTransfers', 'canViewStatements', 'canEdit', 'hasFioToken', 'canManageAutoDown'));
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $account  = BankAccount::findOrFail($id);
        $fioToken = Setting::get('fio_api_token_bank_account_' . $id, '');

        return view('bank_accounts.edit', compact('account', 'fioToken'));
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $account = BankAccount::findOrFail($id);

        $data = $request->validate([
            'IBAN'      => ['nullable', 'string', 'max:34'],
            'SWIFT'     => ['nullable', 'string', 'max:11'],
            'fio_token' => ['nullable', 'string', 'max:200'],
        ]);

        $account->IBAN  = $data['IBAN']  ?? null;
        $account->SWIFT = $data['SWIFT'] ?? null;
        $account->save();

        Setting::set('fio_api_token_bank_account_' . $id, $data['fio_token'] ?? '');

        return redirect()->route('bank_accounts.show', $id)->with('success', 'Bankovní účet byl uložen.');
    }
}
