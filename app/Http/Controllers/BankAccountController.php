<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Setting;
use App\Services\AclService;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';
    private const ACL_VALUE   = 'bank_accounts';

    public function __construct(private AclService $acl) {}

    private function can(string $action): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index()
    {
        abort_unless($this->can('view_all'), 403);

        $accounts = BankAccount::with('member')->orderBy('name')->get();

        return view('bank_accounts.index', [
            'accounts' => $accounts,
            'canView'  => true,
        ]);
    }

    public function show(int $id)
    {
        abort_unless($this->can('view_all'), 403);

        $account = BankAccount::with('member')->findOrFail($id);

        $canViewTransfers  = $this->acl->hasAccess(auth()->id(), 'view_all', self::ACL_SECTION, 'bank_transfers');
        $canViewStatements = $this->acl->hasAccess(auth()->id(), 'view_all', self::ACL_SECTION, 'bank_statements');
        $canEdit           = $this->can('edit_all');
        $hasFioToken       = !empty(Setting::get('fio_api_token_bank_account_' . $account->id));

        return view('bank_accounts.show', compact('account', 'canViewTransfers', 'canViewStatements', 'canEdit', 'hasFioToken'));
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
