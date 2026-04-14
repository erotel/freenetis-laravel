<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PohodaRefundQueueController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($this->aclCheck('view_all', 'Accounts_Controller', 'invoices'), 403);

        $filterStatus = $request->input('status', '');

        $query = DB::table('pohoda_refund_queue as q')
            ->join('members as m', 'm.id', '=', 'q.member_id')
            ->select(
                'q.id', 'q.member_id', 'q.doc_number', 'q.refund_account',
                'q.amount', 'q.currency', 'q.reason', 'q.note',
                'q.status', 'q.created_at', 'q.exported_at',
                'm.name as member_name'
            );

        if ($filterStatus !== '') {
            $query->where('q.status', $filterStatus);
        }

        $items = $query->orderByDesc('q.id')->paginate(50)->withQueryString();

        return view('pohoda_refund_queue.index', [
            'items'        => $items,
            'filterStatus' => $filterStatus,
        ]);
    }
}
