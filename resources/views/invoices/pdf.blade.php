<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; margin: 0; padding: 0; color: #222; }
    h1 { font-size: 18px; text-align: center; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px; }
    .parties { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    .parties td { width: 50%; vertical-align: top; padding: 0 10px 0 0; }
    .parties td:last-child { padding: 0 0 0 10px; border-left: 1px solid #ccc; }
    .box-title { font-size: 10px; font-weight: bold; text-transform: uppercase; color: #666;
                 border-bottom: 1px solid #333; padding-bottom: 3px; margin-bottom: 8px; }
    .party-name { font-size: 13px; font-weight: bold; margin-bottom: 4px; }
    .party-line { margin-bottom: 2px; }
    .meta { width: 100%; border-collapse: collapse; margin-bottom: 20px;
            border: 1px solid #ddd; }
    .meta td, .meta th { padding: 4px 8px; border: 1px solid #ddd; font-size: 10px; }
    .meta th { background: #f0f0f0; font-weight: bold; width: 110px; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
    table.items th { background: #e8e8e8; padding: 5px 7px; text-align: left;
                     border: 1px solid #ccc; font-size: 10px; }
    table.items td { padding: 5px 7px; border: 1px solid #ddd; font-size: 10px; }
    .right { text-align: right; }
    .total-row td { font-weight: bold; background: #f5f5f5; border-top: 2px solid #999; }
    .note { margin-top: 14px; font-size: 9px; color: #777; }
</style>
</head>
<body>

<h1>Faktura č. {{ (int)$invoice->invoice_nr }}</h1>

<table class="parties">
    <tr>
        <td>
            <div class="box-title">Dodavatel</div>
            <div class="party-name">{{ $org?->name ?? 'PVfree.net, z.s.' }}</div>
            @if($orgStreet)
                <div class="party-line">{{ $orgStreet }}</div>
            @endif
            @if($orgTown)
                <div class="party-line">{{ $orgTown }}</div>
            @endif
            @if($org?->organization_identifier)
                <div class="party-line">IČO: {{ $org->organization_identifier }}</div>
            @endif
            @if($org?->vat_organization_identifier)
                <div class="party-line">DIČ: {{ $org->vat_organization_identifier }}</div>
            @endif
            @if($bankAccount)
                <div class="party-line">Účet: {{ $bankAccount->account_nr }}/{{ $bankAccount->bank_nr }}</div>
            @endif
        </td>
        <td>
            <div class="box-title">Odběratel</div>
            <div class="party-name">{{ $invoice->partner_name }}</div>
            @php
                $addrLine = trim(($invoice->partner_street ?? '') . ' ' . ($invoice->partner_street_number ?? ''));
                $townLine  = trim(($invoice->partner_town ?? '') . ($invoice->partner_zip_code ? ', ' . $invoice->partner_zip_code : ''));
            @endphp
            @if($addrLine)
                <div class="party-line">{{ $addrLine }}</div>
            @endif
            @if($townLine)
                <div class="party-line">{{ $townLine }}</div>
            @endif
            @if($invoice->organization_identifier)
                <div class="party-line">IČO: {{ $invoice->organization_identifier }}</div>
            @endif
            @if($invoice->vat_organization_identifier)
                <div class="party-line">DIČ: {{ $invoice->vat_organization_identifier }}</div>
            @endif
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <th>Číslo faktury</th>
        <td>{{ (int)$invoice->invoice_nr }}</td>
        <th>Variabilní symbol</th>
        <td>{{ (int)$invoice->var_sym }}</td>
    </tr>
    <tr>
        <th>Datum vystavení</th>
        <td>{{ $invoice->date_inv }}</td>
        <th>Datum splatnosti</th>
        <td>{{ $invoice->date_due }}</td>
    </tr>
    <tr>
        <th>DUZP</th>
        <td>{{ $invoice->date_vat }}</td>
        <th>Měna</th>
        <td>{{ $invoice->currency }}</td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th>Název</th>
            <th>Kód</th>
            <th class="right">Mn.</th>
            <th class="right">Cena bez DPH</th>
            <th class="right">DPH %</th>
            <th class="right">Cena s DPH</th>
        </tr>
    </thead>
    <tbody>
        @foreach($invoice->items as $item)
        <tr>
            <td>{{ $item->name }}</td>
            <td>{{ $item->code }}</td>
            <td class="right">{{ $item->quantity }}</td>
            <td class="right">{{ number_format($item->price * $item->quantity, 2, ',', ' ') }} Kč</td>
            <td class="right">{{ number_format($item->vat * 100, 0) }} %</td>
            <td class="right">{{ number_format($item->price_vat, 2, ',', ' ') }} Kč</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total-row">
            <td colspan="3">Celkem</td>
            <td class="right">{{ number_format($invoice->price_total, 2, ',', ' ') }} Kč</td>
            <td></td>
            <td class="right">{{ number_format($invoice->price_vat_total, 2, ',', ' ') }} Kč</td>
        </tr>
    </tfoot>
</table>

@if($invoice->note)
    <p class="note">Poznámka: {{ $invoice->note }}</p>
@endif

</body>
</html>
