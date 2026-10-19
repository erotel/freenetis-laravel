<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Repositories\AccountRepository;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';
    private const ACL_VALUE   = 'accounts';

    public function __construct(private AccountRepository $accounts)
    {
    }

    private function can(string $action, string $value = self::ACL_VALUE): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, $value);
    }

    public function index(Request $request)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $allowedSorts = ['id', 'name', 'balance'];
        $sort = in_array($request->query('sort'), $allowedSorts, true)
            ? $request->query('sort')
            : 'id';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->query('record_per_page', 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300, 350, 400, 450, 500], true)) {
            $perPage = 50;
        }

        $type = $request->query('type', 'all');
        $q    = trim((string) $request->query('q', ''));

        $accounts = $this->accounts->paginate($type, $q, $sort, $dir, $perPage);

        return view('accounts.index', [
            'accounts'        => $accounts,
            'sort'            => $sort,
            'dir'             => $dir,
            'perPage'         => $perPage,
            'type'            => $type,
            'q'               => $q,
            'canNew'          => $this->can('new_all'),
            'canEdit'         => $this->can('edit_all'),
            'canViewTransfers'=> $this->can('view_all', 'transfers'),
        ]);
    }

    public function show(int $id)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $account = $this->accounts->findWithRelationsOrFail($id);
        [$totalIn, $totalOut] = $this->accounts->totals($account->id);

        return view('accounts.show', [
            'account'         => $account,
            'totalIn'         => $totalIn,
            'totalOut'        => $totalOut,
            'canEdit'         => $this->can('edit_all'),
            'canViewTransfers'=> $this->can('view_all', 'transfers'),
        ]);
    }

    public function create()
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        return view('accounts.create');
    }

    public function store(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $data = $request->validate([
            'name'    => 'required|string|min:3|max:100',
            'comment' => 'nullable|string|max:254',
        ]);

        $account = $this->accounts->createProject($data['name'], $data['comment'] ?? null);

        session()->flash('success', 'Projektový účet byl úspěšně přidán.');
        return redirect()->route('accounts.show', $account->id);
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $account = $this->accounts->findWithAttributeOrFail($id);

        return view('accounts.edit', ['account' => $account]);
    }

    public function update(Request $request, int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $account = $this->accounts->findOrFail($id);

        $data = $request->validate([
            'name'    => 'required|string|min:3|max:100',
            'comment' => 'nullable|string|max:254',
        ]);

        $this->accounts->update($account, $data);

        session()->flash('success', 'Účet byl úspěšně upraven.');
        return redirect()->route('accounts.show', $id);
    }
}
