@extends('layouts.app')

@section('title', $member ? 'Faktury člena ' . $member->name : 'Faktury')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        @if($member)
            <a href="{{ route('members.index') }}">Členové</a> &raquo;
            <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
            Faktury
        @else
            Faktury
        @endif
    </div>
@endsection

@section('content')
    <h2>{{ $member ? 'Faktury člena ' . $member->name : 'Faktury' }}</h2>

    @if($member)
        <p><a href="{{ route('members.show', $member->id) }}">&larr; Zpět na profil člena</a></p>
    @endif

    @if(!$member)
        <form method="GET" action="{{ route('invoices.index') }}" style="margin-bottom:10px;">
            <label>Typ:
                <select name="type" onchange="this.form.submit()">
                    <option value="all" @selected($filterType === 'all')>Všechny</option>
                    <option value="0" @selected($filterType === '0')>Vydané</option>
                    <option value="1" @selected($filterType === '1')>Přijaté</option>
                </select>
            </label>
        </form>
    @endif

    {{ $invoices->links() }}
    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Číslo faktury</th>
                <th>Partner</th>
                <th>Typ</th>
                <th>Datum vystavení</th>
                <th>Datum splatnosti</th>
                <th class="right">Cena bez DPH</th>
                <th class="right">Cena s DPH</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invoices as $invoice)
                <tr>
                    <td>{{ $invoice->id }}</td>
                    <td>{{ (int)$invoice->invoice_nr }}</td>
                    <td>
                        @if($invoice->member)
                            <a href="{{ route('members.show', $invoice->member_id) }}">
                                {{ $invoice->member->name }}
                            </a>
                        @elseif($invoice->partner_name)
                            {{ $invoice->partner_name }}
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $invoice->getTypeLabel() }}</td>
                    <td>{{ $invoice->date_inv }}</td>
                    <td>{{ $invoice->date_due }}</td>
                    <td class="right">{{ number_format($invoice->price_total, 2, ',', ' ') }} {{ $invoice->currency }}</td>
                    <td class="right">{{ number_format($invoice->price_vat_total, 2, ',', ' ') }} {{ $invoice->currency }}</td>
                    <td class="action">
                        <a href="{{ route('invoices.show', $invoice->id) }}"
                           title="Detail">
                            <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                        </a>
                        @if($invoice->pdf_filename && file_exists($invoice->pdf_filename))
                            &nbsp;<a href="{{ route('invoices.pdf', $invoice->id) }}" title="Stáhnout PDF">PDF</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" style="text-align:center;">Žádné faktury.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top:8px;">
        {{ $invoices->links() }}
    </div>
@endsection
