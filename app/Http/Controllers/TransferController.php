<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Repositories\AccountRepository;
use App\Domains\Billing\Repositories\TransferRepository;
use App\Http\Filters\TransferFilter;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(private TransferRepository $transfers)
    {
    }

    private function can(string $action = 'view_all'): bool
    {
        return $this->aclCheck($action, 'Accounts_Controller', 'transfers');
    }

    public function index(Request $request)
    {
        $sort    = in_array($request->sort, ['id', 'datetime', 'amount']) ? $request->sort : 'datetime';
        $dir     = $request->dir === 'asc' ? 'asc' : 'desc';
        $perPage = (int) ($request->record_per_page ?? 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300])) {
            $perPage = 50;
        }

        $advancedFilters = $request->input('filters', []);
        $transfers = $this->transfers->search(
            (int) $request->account_id ?: null,
            (int) $request->member_id ?: null,
            $advancedFilters,
            $sort,
            $dir,
            $perPage
        );

        return view('transfers.index', [
            'transfers'      => $transfers,
            'sort'           => $sort,
            'dir'            => $dir,
            'perPage'        => $perPage,
            'filterFields'   => TransferFilter::fields(),
            'currentFilters' => $advancedFilters,
        ]);
    }

    public function showByAccount(Request $request, int $accountId, AccountRepository $accounts)
    {
        abort_unless($this->can(), 403);

        $account = $accounts->findWithRelationsOrFail($accountId);

        $sort = in_array($request->sort, ['id', 'datetime', 'amount'], true) ? $request->sort : 'datetime';
        $dir  = $request->dir === 'asc' ? 'asc' : 'desc';

        $transfers = $this->transfers->ofAccount($accountId, $sort, $dir, 50);

        return view('transfers.show_by_account', compact('account', 'transfers', 'accountId', 'sort', 'dir'));
    }

    public function show(int $id)
    {
        abort_unless($this->can(), 403);

        return view('transfers.show', ['transfer' => $this->transfers->findForDetailOrFail($id)]);
    }
}
