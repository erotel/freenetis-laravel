@extends('layouts.app')
@section('title', $member ? 'Faktury člena ' . $member->name : 'Faktury')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    @if($member)
        <a href="{{ route('members.index') }}">Členové</a> &raquo;
        <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo; Faktury
    @else Faktury
    @endif
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>{{ $member ? 'Faktury člena ' . $member->name : 'Faktury' }}</h2></div>
<div class="m-subtitle">Celkem: {{ $invoices->total() }} záznamů</div>

@if($member)
<div class="m-actions">
    <a class="m-btn" href="{{ route('members.show', $member->id) }}">&larr; Profil člena</a>
</div>
@else
<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" action="{{ route('invoices.index') }}" style="display:flex;align-items:center;gap:8px">
        <div class="m-form-label">Typ:</div>
        <select class="m-form-select" style="width:130px" name="type" onchange="this.form.submit()">
            <option value="all" @selected($filterType === 'all')>Všechny</option>
            <option value="0" @selected($filterType === '0')>Vydané</option>
            <option value="1" @selected($filterType === '1')>Přijaté</option>
        </select>
    </form>
</div>
@endif

<div style="margin-bottom:8px">{{ $invoices->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th style="width:110px">Číslo</th>
            <th>Partner</th>
            <th style="width:80px">Typ</th>
            <th style="width:100px">Vystavení</th>
            <th style="width:100px">Splatnost</th>
            <th style="width:110px;text-align:right">Bez DPH</th>
            <th style="width:110px;text-align:right">S DPH</th>
            <th style="width:70px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($invoices as $invoice)
        <tr>
            <td>{{ $invoice->id }}</td>
            <td style="font-family:monospace">{{ (int)$invoice->invoice_nr }}</td>
            <td>
                @if($invoice->member)
                    <a class="m-link" href="{{ route('members.show', $invoice->member_id) }}">{{ $invoice->member->name }}</a>
                @elseif($invoice->partner_name) {{ $invoice->partner_name }}
                @else — @endif
            </td>
            <td style="font-size:14px">{{ $invoice->getTypeLabel() }}</td>
            <td style="font-size:14px">{{ $invoice->date_inv }}</td>
            <td style="font-size:14px">{{ $invoice->date_due }}</td>
            <td style="text-align:right;font-family:monospace;font-size:14px">{{ number_format($invoice->price_total, 2, ',', ' ') }} {{ $invoice->currency }}</td>
            <td style="text-align:right;font-family:monospace;font-size:14px">{{ number_format($invoice->price_vat_total, 2, ',', ' ') }} {{ $invoice->currency }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('invoices.show', $invoice->id) }}">Detail</a>
                    @if($invoice->pdf_filename && file_exists($invoice->pdf_filename))
                    <a class="m-link-sm" href="{{ route('invoices.pdf', $invoice->id) }}">PDF</a>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="9" style="text-align:center;color:#aaa;padding:2rem">Žádné faktury.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $invoices->links() }}</div>
</div>
@endsection
