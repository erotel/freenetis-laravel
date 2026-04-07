<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\OutgoingPayment;
use App\Models\Setting;
use App\Services\AclService;
use App\Services\FioApiService;
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

        // Load export bank accounts from member type routing config
        $exportBankAccountIds = array_filter([
            Setting::get('bank_account_member_type_2'),
            Setting::get('bank_account_member_type_90'),
        ]);

        $exportBankAccounts = BankAccount::whereIn('id', $exportBankAccountIds)
            ->get()
            ->map(fn($ba) => [
                'id'        => $ba->id,
                'name'      => $ba->name,
                'full_nr'   => $ba->full_account_number,
                'has_token' => !empty(Setting::get('fio_api_token_bank_account_' . $ba->id)),
            ]);

        return view('outgoing_payments.index', [
            'payments'           => $payments,
            'currentStatus'      => $status,
            'statusLabels'       => OutgoingPayment::statusLabels(),
            'statusColors'       => OutgoingPayment::statusColors(),
            'reasonLabels'       => OutgoingPayment::reasonLabels(),
            'canEdit'            => $this->canEdit(),
            'exportBankAccounts' => $exportBankAccounts,
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

    public function export(int $bankAccountId)
    {
        abort_unless($this->canEdit(), 403);

        $bankAccount = BankAccount::findOrFail($bankAccountId);

        $payments = OutgoingPayment::where('bank_account_id', $bankAccountId)
            ->where('status', OutgoingPayment::STATUS_APPROVED)
            ->get();

        if ($payments->isEmpty()) {
            return redirect()->route('outgoing_payments.index')
                ->with('error', 'Žádné schválené platby k exportu pro tento účet.');
        }

        $xml   = $this->buildFioXml($payments, $bankAccount);
        $token = Setting::get('fio_api_token_bank_account_' . $bankAccountId);

        if ($token) {
            // Send directly to FIO API
            try {
                $fio      = app(FioApiService::class);
                $response = $fio->importPayments($token, $xml);

                $now = now();
                foreach ($payments as $payment) {
                    $payment->update([
                        'status'       => OutgoingPayment::STATUS_EXPORTED,
                        'exported_at'  => $now,
                        'api_response' => $response,
                    ]);
                }

                // Append waiting note to linked bank_transfer comments
                foreach ($payments as $payment) {
                    if ($payment->transfer_id) {
                        BankTransfer::where('transfer_id', $payment->transfer_id)
                            ->update([
                                'comment' => \Illuminate\Support\Facades\DB::raw(
                                    "CONCAT(COALESCE(comment, ''), IF(comment IS NULL OR comment = '', '', ' | '), 'Čeká se na platbu z banky')"
                                )
                            ]);
                    }
                }

                return redirect()->route('outgoing_payments.index')
                    ->with('success', 'Platby byly odeslány do FIO (' . $payments->count() . ' plateb).');
            } catch (\RuntimeException $e) {
                return redirect()->route('outgoing_payments.index')
                    ->with('error', 'Chyba FIO API: ' . $e->getMessage());
            }
        } else {
            // No token — download XML file
            $filename = 'platby_' . $bankAccount->account_nr . '_' . now()->format('Ymd_His') . '.xml';

            $now = now();
            foreach ($payments as $payment) {
                $payment->update([
                    'status'          => OutgoingPayment::STATUS_EXPORTED,
                    'exported_at'     => $now,
                    'export_filename' => $filename,
                ]);
            }

            // Append waiting note to linked bank_transfer comments
            foreach ($payments as $payment) {
                if ($payment->transfer_id) {
                    BankTransfer::where('transfer_id', $payment->transfer_id)
                        ->update([
                            'comment' => \Illuminate\Support\Facades\DB::raw(
                                "CONCAT(COALESCE(comment, ''), IF(comment IS NULL OR comment = '', '', ' | '), 'Čeká se na platbu z banky')"
                            )
                        ]);
                }
            }

            return response($xml, 200, [
                'Content-Type'        => 'application/xml; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        }
    }

    private function buildFioXml($payments, BankAccount $bankAccount): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<Import xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
              . ' xsi:noNamespaceSchemaLocation="http://www.fio.cz/schema/importIB.xsd">' . "\n";
        $xml .= '<Orders>' . "\n";

        foreach ($payments as $payment) {
            $parts     = explode('/', $payment->target_account ?? '');
            $accountNr = $parts[0] ?? '';
            $bankNr    = $parts[1] ?? '';

            $xml .= '<DomesticTransaction>' . "\n";
            $xml .= '<accountFrom>' . e($bankAccount->account_nr) . '</accountFrom>' . "\n";
            $xml .= '<currency>' . e($payment->currency ?: 'CZK') . '</currency>' . "\n";
            $xml .= '<amount>' . number_format((float) $payment->amount, 2, '.', '') . '</amount>' . "\n";
            $xml .= '<accountTo>' . e($accountNr) . '</accountTo>' . "\n";
            $xml .= '<bankCode>' . e($bankNr) . '</bankCode>' . "\n";
            if ($payment->variable_symbol) {
                $xml .= '<vs>' . e($payment->variable_symbol) . '</vs>' . "\n";
            }
            $xml .= '<date>' . now()->format('Y-m-d') . '</date>' . "\n";
            $message = trim($payment->message . ' OP #' . $payment->id);
            $xml .= '<messageForRecipient>' . e(mb_substr($message, 0, 140)) . '</messageForRecipient>' . "\n";
            $xml .= '<comment>' . e(mb_substr($message, 0, 255)) . '</comment>' . "\n";
            $xml .= '<paymentType>431001</paymentType>' . "\n";
            $xml .= '</DomesticTransaction>' . "\n";
        }

        $xml .= '</Orders>' . "\n";
        $xml .= '</Import>' . "\n";

        return $xml;
    }
}
