<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Repositories\RefundRepository;
use Illuminate\Http\Request;

class PohodaRefundQueueController extends Controller
{
    public function index(Request $request, RefundRepository $refunds)
    {
        abort_unless($this->aclCheck('view_all', 'Accounts_Controller', 'invoices'), 403);

        $filterStatus = (string) $request->input('status', '');

        return view('pohoda_refund_queue.index', [
            'items'        => $refunds->pohodaQueue($filterStatus, 50),
            'filterStatus' => $filterStatus,
        ]);
    }
}
