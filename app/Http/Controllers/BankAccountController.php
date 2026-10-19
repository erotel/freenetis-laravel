<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Repositories\BankAccountRepository;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';
    private const ACL_VALUE   = 'bank_accounts';

    public function __construct(private BankAccountRepository $accounts)
    {
    }

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $all = $this->accounts->search($q);

        $associationAccounts = $all->where('member_id', 1)->values();
        $memberAccounts      = $all->where('member_id', '!=', 1)->values();

        $canManageAutoDown = $this->aclCheck('view_all', self::ACL_SECTION, 'bank_account_auto_down_config');

        // For association accounts: check which have a FIO token or auto-download rules
        $autoDownFlags = [];
        if ($canManageAutoDown) {
            foreach ($associationAccounts as $account) {
                $autoDownFlags[$account->id] = $this->accounts->hasDownloadRules($account->id);
            }
        }

        return view('bank_accounts.index', compact(
            'associationAccounts', 'memberAccounts', 'canManageAutoDown', 'autoDownFlags', 'q'
        ));
    }

    public function show(int $id)
    {
        abort_unless($this->can('view_all'), 403);

        $account = $this->accounts->findWithMemberOrFail($id);

        $canViewTransfers    = $this->aclCheck('view_all', self::ACL_SECTION, 'bank_transfers');
        $canViewStatements   = $this->aclCheck('view_all', self::ACL_SECTION, 'bank_statements');
        $canEdit             = $this->can('edit_all');
        $hasFioToken         = $this->accounts->fioToken($account->id) !== '';
        $canManageAutoDown   = $this->aclCheck('view_all', self::ACL_SECTION, 'bank_account_auto_down_config');

        return view('bank_accounts.show', compact('account', 'canViewTransfers', 'canViewStatements', 'canEdit', 'hasFioToken', 'canManageAutoDown'));
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $account  = $this->accounts->findOrFail($id);
        $fioToken = $this->accounts->fioToken($id);

        return view('bank_accounts.edit', compact('account', 'fioToken'));
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $account = $this->accounts->findOrFail($id);

        $data = $request->validate([
            'IBAN'      => ['nullable', 'string', 'max:34'],
            'SWIFT'     => ['nullable', 'string', 'max:11'],
            'fio_token' => ['nullable', 'string', 'max:200'],
        ]);

        $this->accounts->setIbanSwift($account, $data['IBAN'] ?? null, $data['SWIFT'] ?? null);
        $this->accounts->setFioToken($id, $data['fio_token'] ?? '');

        return redirect()->route('bank_accounts.show', $id)->with('success', 'Bankovní účet byl uložen.');
    }
}
