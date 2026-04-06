<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transfer;
use App\Services\AclService;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(private AclService $acl) {}

    private function can(string $action = 'view_all'): bool
    {
        return $this->acl->hasAccess(
            auth()->id(), $action, 'Accounts_Controller', 'transfers'
        );
    }

    public function index(Request $request)
    {
        abort_unless($this->can(), 403);

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

        $transfers = $query->paginate($perPage)->withQueryString();

        return view('transfers.index', compact('transfers', 'sort', 'dir', 'perPage'));
    }

    public function showByAccount(Request $request, int $accountId)
    {
        abort_unless($this->can(), 403);

        $account = Account::with(['member', 'accountAttribute'])->findOrFail($accountId);

        $transfers = Transfer::where('origin_id', $accountId)
            ->orWhere('destination_id', $accountId)
            ->with(['origin.member', 'destination.member'])
            ->orderBy('id', 'desc')
            ->paginate(50);

        return view('transfers.show_by_account', compact('account', 'transfers', 'accountId'));
    }

    public function show(int $id)
    {
        abort_unless($this->can(), 403);

        $transfer = Transfer::with([
            'origin.member',
            'destination.member',
            'member',
            'user',
            'previousTransfer.origin',
            'previousTransfer.destination',
        ])->findOrFail($id);

        return view('transfers.show', compact('transfer'));
    }
}
