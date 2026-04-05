<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountAttribute;
use App\Models\Transfer;
use App\Services\AclService;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';
    private const ACL_VALUE   = 'accounts';

    public function __construct(private AclService $acl) {}

    private function can(string $action, string $value = self::ACL_VALUE): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, self::ACL_SECTION, $value);
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

        $query = Account::with(['member', 'accountAttribute']);

        match ($type) {
            'credit'  => $query->whereNotNull('member_id'),
            'project' => $query->where('account_attribute_id', AccountAttribute::PROJECT_ATTRIBUTE_ID),
            'other'   => $query->whereNull('member_id')
                               ->where('account_attribute_id', '!=', AccountAttribute::PROJECT_ATTRIBUTE_ID),
            default   => null,
        };

        $accounts = $query->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();

        return view('accounts.index', [
            'accounts'        => $accounts,
            'sort'            => $sort,
            'dir'             => $dir,
            'perPage'         => $perPage,
            'type'            => $type,
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

        $account = Account::with(['member', 'accountAttribute'])->find($id);
        if (!$account) {
            abort(404);
        }

        $totalIn  = Transfer::where('destination_id', $account->id)->sum('amount');
        $totalOut = Transfer::where('origin_id', $account->id)->sum('amount');

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

        $account = Account::create([
            'name'                 => $data['name'],
            'comment'              => $data['comment'] ?? null,
            'account_attribute_id' => AccountAttribute::PROJECT_ATTRIBUTE_ID,
            'member_id'            => null,
            'balance'              => 0,
        ]);

        session()->flash('success', 'Projektový účet byl úspěšně přidán.');
        return redirect()->route('accounts.show', $account->id);
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $account = Account::with('accountAttribute')->find($id);
        if (!$account) {
            abort(404);
        }

        return view('accounts.edit', ['account' => $account]);
    }

    public function update(Request $request, int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $account = Account::find($id);
        if (!$account) {
            abort(404);
        }

        $data = $request->validate([
            'name'    => 'required|string|min:3|max:100',
            'comment' => 'nullable|string|max:254',
        ]);

        $account->update($data);

        session()->flash('success', 'Účet byl úspěšně upraven.');
        return redirect()->route('accounts.show', $id);
    }
}
