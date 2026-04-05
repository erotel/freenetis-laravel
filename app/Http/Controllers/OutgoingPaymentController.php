<?php

namespace App\Http\Controllers;

use App\Models\OutgoingPayment;
use App\Services\AclService;
use Illuminate\Http\Request;

class OutgoingPaymentController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';

    public function __construct(private AclService $acl) {}

    private function canView(): bool
    {
        return $this->acl->hasAccess(auth()->id(), 'view_all', self::ACL_SECTION, 'bank_transfers');
    }

    private function canEdit(): bool
    {
        return $this->acl->hasAccess(auth()->id(), 'edit_all', self::ACL_SECTION, 'unidentified_transfers');
    }

    public function index(Request $request)
    {
        abort_unless($this->canView(), 403);

        $status = $request->query('status');
        $validStatuses = array_keys(OutgoingPayment::statusLabels());

        $query = OutgoingPayment::with(['bankAccount', 'createdBy', 'approvedBy'])
            ->orderByDesc('created_at');

        if ($status && in_array($status, $validStatuses, true)) {
            $query->where('status', $status);
        }

        $payments = $query->paginate(50)->withQueryString();

        return view('outgoing_payments.index', [
            'payments'     => $payments,
            'currentStatus'=> $status,
            'statusLabels' => OutgoingPayment::statusLabels(),
            'statusColors' => OutgoingPayment::statusColors(),
            'reasonLabels' => OutgoingPayment::reasonLabels(),
            'canEdit'      => $this->canEdit(),
        ]);
    }

    public function show(int $id)
    {
        abort_unless($this->canView(), 403);

        $payment = OutgoingPayment::with(['bankAccount', 'transfer', 'createdBy', 'approvedBy'])->findOrFail($id);

        return view('outgoing_payments.show', [
            'payment'      => $payment,
            'statusLabels' => OutgoingPayment::statusLabels(),
            'statusColors' => OutgoingPayment::statusColors(),
            'reasonLabels' => OutgoingPayment::reasonLabels(),
            'canEdit'      => $this->canEdit(),
        ]);
    }

    public function approve(int $id)
    {
        abort_unless($this->canEdit(), 403);

        $payment = OutgoingPayment::findOrFail($id);

        if ($payment->status !== OutgoingPayment::STATUS_DRAFT) {
            session()->flash('error', 'Platbu lze schválit pouze ve stavu Koncept.');
            return redirect()->back();
        }

        $payment->update([
            'status'      => OutgoingPayment::STATUS_APPROVED,
            'approved_by' => auth()->id(),
        ]);

        session()->flash('success', 'Platba byla schválena.');
        return redirect()->route('outgoing_payments.index');
    }

    public function cancel(int $id)
    {
        abort_unless($this->canEdit(), 403);

        $payment = OutgoingPayment::findOrFail($id);

        if (!in_array($payment->status, [OutgoingPayment::STATUS_DRAFT, OutgoingPayment::STATUS_APPROVED], true)) {
            session()->flash('error', 'Platbu nelze zrušit v aktuálním stavu.');
            return redirect()->back();
        }

        $payment->update(['status' => OutgoingPayment::STATUS_CANCELLED]);

        session()->flash('success', 'Platba byla zrušena.');
        return redirect()->route('outgoing_payments.index');
    }
}
