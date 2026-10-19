<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Actions\ApproveOutgoingPaymentAction;
use App\Domains\Billing\Actions\CancelOutgoingPaymentAction;
use App\Domains\Billing\Actions\ExportOutgoingPaymentsAction;
use App\Domains\Billing\Exceptions\OutgoingPaymentRejected;
use App\Domains\Billing\Repositories\OutgoingPaymentRepository;
use App\Models\OutgoingPayment;
use Illuminate\Http\Request;

/** Odchozí platby: přehled, schválení, zrušení, export do FIO; logika v doméně Billing. */
class OutgoingPaymentController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';

    public function __construct(private OutgoingPaymentRepository $payments) {}

    private function canView(): bool
    {
        return $this->aclCheck('view_all', self::ACL_SECTION, 'bank_transfers');
    }

    private function canEdit(): bool
    {
        return $this->aclCheck('edit_all', self::ACL_SECTION, 'unidentified_transfers');
    }

    private function labels(): array
    {
        return [
            'statusLabels' => OutgoingPayment::statusLabels(),
            'statusColors' => OutgoingPayment::statusColors(),
            'reasonLabels' => OutgoingPayment::reasonLabels(),
            'canEdit'      => $this->canEdit(),
        ];
    }

    public function index(Request $request)
    {
        $status = $request->query('status');
        $valid  = $status && in_array($status, array_keys(OutgoingPayment::statusLabels()), true);

        return view('outgoing_payments.index', [
            'payments'           => $this->payments->paginate($valid ? $status : null, 50),
            'currentStatus'      => $status,
            'exportBankAccounts' => $this->payments->exportAccounts(),
        ] + $this->labels());
    }

    public function show(int $id)
    {
        abort_unless($this->canView(), 403);
        return view('outgoing_payments.show', ['payment' => $this->payments->findForDetailOrFail($id)] + $this->labels());
    }

    public function approve(int $id, ApproveOutgoingPaymentAction $approve)
    {
        abort_unless($this->canEdit(), 403);
        try {
            $approve->handle($this->payments->findOrFail($id), auth()->id());
        } catch (OutgoingPaymentRejected $e) {
            session()->flash('error', $e->getMessage());
            return redirect()->back();
        }
        session()->flash('success', 'Platba byla schválena.');
        return redirect()->route('outgoing_payments.index');
    }

    public function cancel(int $id, CancelOutgoingPaymentAction $cancel)
    {
        abort_unless($this->canEdit(), 403);
        try {
            $cancel->handle($this->payments->findOrFail($id));
        } catch (OutgoingPaymentRejected $e) {
            session()->flash('error', $e->getMessage());
            return redirect()->back();
        }
        session()->flash('success', 'Platba byla zrušena.');
        return redirect()->route('outgoing_payments.index');
    }

    /** Schválené platby účtu: s FIO tokenem rovnou do FIO, jinak XML ke stažení. */
    public function export(int $bankAccountId, ExportOutgoingPaymentsAction $export)
    {
        abort_unless($this->canEdit(), 403);
        $bankAccount = $this->payments->bankAccountOrFail($bankAccountId);

        try {
            $result = $export->handle($bankAccount);
        } catch (OutgoingPaymentRejected $e) {
            return redirect()->route('outgoing_payments.index')->with('error', $e->getMessage());
        }

        if ($result['filename'] === null) {
            return redirect()->route('outgoing_payments.index')->with('success', 'Platby byly odeslány do FIO (' . $result['count'] . ' plateb).');
        }
        return response($result['xml'], 200, [
            'Content-Type'        => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
        ]);
    }
}
