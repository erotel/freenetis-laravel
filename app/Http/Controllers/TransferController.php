<?php

namespace App\Http\Controllers;

use App\Http\Filters\TransferFilter;
use App\Models\Account;
use App\Models\Transfer;
use Illuminate\Http\Request;

class TransferController extends Controller
{
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

        $query = Transfer::with(['origin.member', 'destination.member', 'member'])
            ->orderBy($sort, $dir);

        if ($accountId = (int) $request->account_id) {
            $query->where(function ($q) use ($accountId) {
                $q->where('origin_id', $accountId)
                  ->orWhere('destination_id', $accountId);
            });
        }

        if ($memberId = (int) $request->member_id) {
            $query->where('member_id', $memberId);
        }

        $advancedFilters = $request->input('filters', []);
        if (!empty($advancedFilters)) {
            TransferFilter::apply($query, $advancedFilters);
        }

        $transfers = $query->paginate($perPage)->withQueryString();

        return view('transfers.index', [
            'transfers'      => $transfers,
            'sort'           => $sort,
            'dir'            => $dir,
            'perPage'        => $perPage,
            'filterFields'   => TransferFilter::fields(),
            'currentFilters' => $advancedFilters,
        ]);
    }

    public function showByAccount(Request $request, int $accountId)
    {
        abort_unless($this->can(), 403);

        $account = Account::with(['member', 'accountAttribute'])->findOrFail($accountId);

        $sort = in_array($request->sort, ['id', 'datetime', 'amount'], true) ? $request->sort : 'datetime';
        $dir  = $request->dir === 'asc' ? 'asc' : 'desc';

        $transfers = Transfer::where(function ($q) use ($accountId) {
                $q->where('origin_id', $accountId)
                  ->orWhere('destination_id', $accountId);
            })
            ->with(['origin.member', 'destination.member'])
            ->orderBy($sort, $dir)
            ->paginate(50)
            ->withQueryString();

        return view('transfers.show_by_account', compact('account', 'transfers', 'accountId', 'sort', 'dir'));
    }

    public function show(int $id)
    {
        abort_unless($this->can(), 403);

        $transfer = Transfer::with([
            'origin.member',
            'destination.member',
            'member',
            'user',
            'previousTransfer.origin.member',
            'previousTransfer.destination.member',
            'previousTransfer.member',
            'previousTransfer.user',
            'previousTransfer.bankTransfer.originAccount',
            'previousTransfer.bankTransfer.destinationAccount',
            'previousTransfer.bankTransfer.bankStatement',
            'bankTransfer.originAccount',
            'bankTransfer.destinationAccount',
            'bankTransfer.bankStatement',
            'dependentTransfers.origin',
            'dependentTransfers.destination',
        ])->findOrFail($id);

        return view('transfers.show', compact('transfer'));
    }
}
