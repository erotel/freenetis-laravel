<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
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

        $account = BankAccount::with(['member', 'bankStatements' => function ($q) {
            $q->orderBy('from', 'desc')->limit(10);
        }])->findOrFail($id);

        $canViewTransfers  = $this->acl->hasAccess(auth()->id(), 'view_all', self::ACL_SECTION, 'bank_transfers');
        $canViewStatements = $this->acl->hasAccess(auth()->id(), 'view_all', self::ACL_SECTION, 'bank_statements');

        return view('bank_accounts.show', compact('account', 'canViewTransfers', 'canViewStatements'));
    }
}
