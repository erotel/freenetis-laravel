@extends('layouts.app')

@section('title', 'Faktura č. ' . (int)$invoice->invoice_nr)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('invoices.index') }}">Faktury</a> &raquo;
        Faktura č. {{ (int)$invoice->invoice_nr }}
    </div>
@endsection

@section('content')
    <h2>Faktura č. {{ (int)$invoice->invoice_nr }}</h2>

    <p>
        <a href="{{ route('invoices.index') }}">&larr; Zpět na seznam faktur</a>
        @if($invoice->pdf_filename && file_exists($invoice->pdf_filename))
            &nbsp;|&nbsp;
            <a href="{{ route('invoices.pdf', $invoice->id) }}">&#11015; Stáhnout PDF (faktura_{{ (int)$invoice->invoice_nr }}.pdf)</a>
        @endif
    </p>

    <table class="extended" cellspacing="0">
        <thead>
            <tr><th colspan="2">Informace o faktuře</th></tr>
        </thead>
        <tbody>
            <tr>
                <th>Číslo faktury</th>
                <td>{{ (int)$invoice->invoice_nr }}</td>
            </tr>
            <tr>
                <th>Typ</th>
                <td>{{ $invoice->getTypeLabel() }}</td>
            </tr>
            <tr>
                <th>Partner</th>
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
                    @if($invoice->partner_company)
                        <br><small>{{ $invoice->partner_company }}</small>
                    @endif
                    @php
                        $addrParts = array_filter([
                            trim(($invoice->partner_street ?? '') . ' ' . ($invoice->partner_street_number ?? '')),
                            $invoice->partner_town ?? null,
                            $invoice->partner_zip_code ?? null,
                            $invoice->partner_country ?? null,
                        ]);
                    @endphp
                    @if($addrParts)
                        <br><small>{{ implode(', ', $addrParts) }}</small>
                    @endif
                    @if($invoice->organization_identifier)
                        <br><small>IČO: {{ $invoice->organization_identifier }}</small>
                    @endif
                    @if($invoice->vat_organization_identifier)
                        <small> / DIČ: {{ $invoice->vat_organization_identifier }}</small>
                    @endif
                </td>
            </tr>
            <tr>
                <th>Variabilní symbol</th>
                <td>{{ (int)$invoice->var_sym }}</td>
            </tr>
            @if($invoice->con_sym)
                <tr>
                    <th>Konstantní symbol</th>
                    <td>{{ (int)$invoice->con_sym }}</td>
                </tr>
            @endif
            <tr>
                <th>Datum vystavení</th>
                <td>{{ $invoice->date_inv }}</td>
            </tr>
            <tr>
                <th>Datum splatnosti</th>
                <td>{{ $invoice->date_due }}</td>
            </tr>
            <tr>
                <th>DUZP</th>
                <td>{{ $invoice->date_vat }}</td>
            </tr>
            <tr>
                <th>Měna</th>
                <td>{{ $invoice->currency }}</td>
            </tr>
            @if($invoice->account_nr)
                <tr>
                    <th>Číslo účtu</th>
                    <td>{{ $invoice->account_nr }}</td>
                </tr>
            @endif
            @if($invoice->note)
                <tr>
                    <th>Poznámka</th>
                    <td>{{ $invoice->note }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <h3 style="margin-top:16px;">Položky faktury</h3>

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>Název</th>
                <th>Kód</th>
                <th class="right">Množství</th>
                <th class="right">Cena bez DPH</th>
                <th class="right">DPH %</th>
                <th class="right">Cena s DPH</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invoice->items as $item)
                <tr>
                    <td>{{ $item->name }}</td>
                    <td>{{ $item->code }}</td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">{{ number_format($item->price * $item->quantity, 2, ',', ' ') }}</td>
                    <td class="right">{{ number_format($item->vat * 100, 0) }} %</td>
                    <td class="right">{{ number_format($item->price_vat, 2, ',', ' ') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align:center;">Žádné položky.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3" style="text-align:right;">Celkem bez DPH:</th>
                <th class="right">{{ number_format($invoice->price_total, 2, ',', ' ') }} {{ $invoice->currency }}</th>
                <th></th>
                <th></th>
            </tr>
            <tr>
                <th colspan="3" style="text-align:right;">Celkem s DPH:</th>
                <th></th>
                <th></th>
                <th class="right">{{ number_format($invoice->price_vat_total, 2, ',', ' ') }} {{ $invoice->currency }}</th>
            </tr>
        </tfoot>
    </table>
@endsection
