<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Member;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    private function canView(): bool
    {
        return $this->aclCheck('view_all', 'Accounts_Controller', 'invoices');
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $query = Invoice::with(['member', 'items'])
            ->orderBy('date_inv', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('member_id')) {
            $query->where('member_id', (int) $request->member_id);
        }
        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('invoice_type', (int) $request->type);
        }

        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like, $q) {
                $w->where('partner_name',    'like', $like)
                  ->orWhere('partner_company', 'like', $like)
                  ->orWhere('note',           'like', $like)
                  ->orWhereHas('member', fn ($m) => $m->where('name', 'like', $like));
                if (ctype_digit($q)) {
                    $n = (int) $q;
                    $w->orWhere('id', $n)
                      ->orWhere('invoice_nr', $n)
                      ->orWhere('member_id',  $n)
                      ->orWhere('var_sym',    $n);
                }
            });
        }

        $invoices = $query->paginate(50)->withQueryString();

        return view('invoices.index', [
            'invoices'   => $invoices,
            'member'     => null,
            'filterType' => $request->input('type', 'all'),
            'q'          => $q,
        ]);
    }

    public function show(int $id)
    {
        if (!$this->canView()) {
            abort(403);
        }

        $invoice = Invoice::with(['member', 'items'])->findOrFail($id);

        return view('invoices.show', compact('invoice'));
    }

    public function downloadPdf(int $id)
    {
        $invoice = Invoice::findOrFail($id);

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

    public function showByMember(int $memberId)
    {
        $ownMemberId = auth()->user()?->member_id;
        if ($memberId != $ownMemberId && !$this->canView()) {
            abort(403);
        }

        $member = Member::findOrFail($memberId);

        $invoices = Invoice::with('items')
            ->where('member_id', $memberId)
            ->orderBy('date_inv', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString();

        return view('invoices.index', [
            'invoices'   => $invoices,
            'member'     => $member,
            'filterType' => 'all',
            'q'          => '',
        ]);
    }
}
