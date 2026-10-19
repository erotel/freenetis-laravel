<?php

namespace App\Http\Controllers;

use App\Domains\Audit\Repositories\AuditTrailRepository;
use App\Domains\Billing\Repositories\InvoiceRepository;
use App\Domains\Members\Repositories\MemberRepository;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceRepository $invoices)
    {
    }

    private function canView(): bool
    {
        return $this->aclCheck('view_all', 'Accounts_Controller', 'invoices');
    }

    public function index(Request $request)
    {
        $q           = trim((string) $request->query('q', ''));
        $pohodaState = $request->input('pohoda', 'all');

        $invoices = $this->invoices->search(
            $request->filled('member_id') ? (int) $request->member_id : null,
            $request->filled('type') && $request->type !== 'all' ? (int) $request->type : null,
            in_array($pohodaState, ['new', 'exported'], true) ? $pohodaState : null,
            $q,
            50
        );

        return view('invoices.index', [
            'invoices'     => $invoices,
            'member'       => null,
            'filterType'   => $request->input('type', 'all'),
            'filterPohoda' => $pohodaState,
            'q'            => $q,
        ]);
    }

    public function show(int $id, AuditTrailRepository $audit)
    {
        if (!$this->canView()) {
            abort(403);
        }

        $invoice = $this->invoices->findWithItemsOrFail($id);

        // Audit trail (NIS2/ZoKB): historie změn faktury a jejích položek.
        $auditHistory = $audit->history([
            'invoices'      => [$id],
            'invoice_items' => $invoice->items->pluck('id')->all(),
        ]);

        return view('invoices.show', compact('invoice', 'auditHistory'));
    }

    public function downloadPdf(int $id)
    {
        $invoice = $this->invoices->findOrFail($id);

        $ownMemberId = auth()->user()?->member_id;
        if ($invoice->member_id != $ownMemberId && !$this->canView()) {
            abort(403);
        }

        if (!$invoice->pdf_filename || !file_exists($invoice->pdf_filename)) {
            return back()->with('error', 'PDF soubor není k dispozici.');
        }

        return response()->download(
            $invoice->pdf_filename,
            'faktura_' . (int)$invoice->invoice_nr . '.pdf',
            ['Content-Type' => 'application/pdf']
        );
    }

    public function showByMember(int $memberId, MemberRepository $members)
    {
        $ownMemberId = auth()->user()?->member_id;
        if ($memberId != $ownMemberId && !$this->canView()) {
            abort(403);
        }

        return view('invoices.index', [
            'invoices'     => $this->invoices->ofMember($memberId, 50),
            'member'       => $members->findOrFail($memberId),
            'filterType'   => 'all',
            'filterPohoda' => 'all',
            'q'            => '',
        ]);
    }
}
