@extends('layouts.app')
@section('title', 'Faktura č. ' . (int)$invoice->invoice_nr)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('invoices.index') }}">Faktury</a> &raquo; Faktura č. {{ (int)$invoice->invoice_nr }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Faktura č. {{ (int)$invoice->invoice_nr }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('invoices.index') }}">&larr; Seznam faktur</a>
    @if($invoice->pdf_filename && file_exists($invoice->pdf_filename))
    <a class="m-btn m-btn-primary" href="{{ route('invoices.pdf', $invoice->id) }}">&#11015; Stáhnout PDF</a>
    @endif
</div>

<div class="m-grid2">
    <div class="m-card">
        <div class="m-card-title">Informace o faktuře</div>
        <div class="m-field"><span class="m-field-label">Číslo faktury</span><span class="m-field-value" style="font-family:monospace">{{ (int)$invoice->invoice_nr }}</span></div>
        <div class="m-field"><span class="m-field-label">Typ</span><span class="m-field-value">{{ $invoice->getTypeLabel() }}</span></div>
        <div class="m-field">
            <span class="m-field-label">Partner</span>
            <span class="m-field-value">
                @if($invoice->member) <a href="{{ route('members.show', $invoice->member_id) }}">{{ $invoice->member->name }}</a>
                @elseif($invoice->partner_name) {{ $invoice->partner_name }}
                @else — @endif
                @if($invoice->partner_company)<br><small style="color:#aaa">{{ $invoice->partner_company }}</small>@endif
                @php $addrParts = array_filter([trim(($invoice->partner_street ?? '') . ' ' . ($invoice->partner_street_number ?? '')), $invoice->partner_town ?? null, $invoice->partner_zip_code ?? null]); @endphp
                @if($addrParts)<br><small style="color:#aaa">{{ implode(', ', $addrParts) }}</small>@endif
                @if($invoice->organization_identifier)<br><small style="color:#aaa">IČO: {{ $invoice->organization_identifier }}</small>@endif
                @if($invoice->vat_organization_identifier)<small style="color:#aaa"> / DIČ: {{ $invoice->vat_organization_identifier }}</small>@endif
            </span>
        </div>
        <div class="m-field"><span class="m-field-label">Variabilní symbol</span><span class="m-field-value" style="font-family:monospace">{{ (int)$invoice->var_sym }}</span></div>
        @if($invoice->con_sym)
        <div class="m-field"><span class="m-field-label">Konstantní symbol</span><span class="m-field-value" style="font-family:monospace">{{ (int)$invoice->con_sym }}</span></div>
        @endif
        <div class="m-field"><span class="m-field-label">Datum vystavení</span><span class="m-field-value">{{ $invoice->date_inv }}</span></div>
        <div class="m-field"><span class="m-field-label">Datum splatnosti</span><span class="m-field-value">{{ $invoice->date_due }}</span></div>
        <div class="m-field"><span class="m-field-label">DUZP</span><span class="m-field-value">{{ $invoice->date_vat }}</span></div>
        <div class="m-field"><span class="m-field-label">Měna</span><span class="m-field-value">{{ $invoice->currency }}</span></div>
        @if($invoice->account_nr)
        <div class="m-field"><span class="m-field-label">Číslo účtu</span><span class="m-field-value" style="font-family:monospace">{{ $invoice->account_nr }}</span></div>
        @endif
        @if($invoice->note)
        <div class="m-field"><span class="m-field-label">Poznámka</span><span class="m-field-value">{{ $invoice->note }}</span></div>
        @endif
    </div>

    <div class="m-card">
        <div class="m-card-title">Celkové částky</div>
        <div class="m-field">
            <span class="m-field-label">Celkem bez DPH</span>
            <span class="m-field-value" style="font-family:monospace">{{ number_format($invoice->price_total, 2, ',', ' ') }} {{ $invoice->currency }}</span>
        </div>
        <div class="m-field">
            <span class="m-field-label">Celkem s DPH</span>
            <span class="m-field-value" style="font-family:monospace;font-weight:500">{{ number_format($invoice->price_vat_total, 2, ',', ' ') }} {{ $invoice->currency }}</span>
        </div>
    </div>
</div>

<div class="m-section">Položky faktury</div>
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th>Název</th>
            <th style="width:100px">Kód</th>
            <th style="width:80px;text-align:right">Množství</th>
            <th style="width:120px;text-align:right">Cena bez DPH</th>
            <th style="width:70px;text-align:right">DPH %</th>
            <th style="width:120px;text-align:right">Cena s DPH</th>
        </tr>
    </thead>
    <tbody>
        @forelse($invoice->items as $item)
        <tr>
            <td>{{ $item->name }}</td>
            <td>{{ $item->code }}</td>
            <td style="text-align:right">{{ $item->quantity }}</td>
            <td style="text-align:right;font-family:monospace;font-size:14px">{{ number_format($item->price * $item->quantity, 2, ',', ' ') }}</td>
            <td style="text-align:right">{{ number_format($item->vat * 100, 0) }} %</td>
            <td style="text-align:right;font-family:monospace;font-size:14px">{{ number_format($item->price_vat, 2, ',', ' ') }}</td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;color:#aaa;padding:1.5rem">Žádné položky.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

</div>
@endsection
