<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="utf-8">
<title>Faktura</title>
<style>
* { box-sizing: border-box; }
body { font-family: dejavusans, sans-serif; font-size: 9pt; color: #000; margin: 0; padding: 0; }

/* Top header row */
.top-row { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
.top-row td { vertical-align: top; }
.supplier-name { font-weight: bold; font-size: 14px; margin-bottom: 5px; }
.invoice-title { text-align: right; font-weight: bold; font-size: 14px; text-transform: uppercase; }
.invoice-number { text-align: right; font-weight: bold; margin-top: 3px; font-size: 11px; }
.vs-line { text-align: right; margin-top: 8px; font-size: 10px; }

/* Recipient box */
.recipient-box { border: 1px solid #000; padding: 6px 8px; margin-top: 6px; font-size: 9px; }
.recipient-box h4 { margin: 0 0 3px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
.recipient-box p { margin: 0; padding: 0; text-decoration: none; }
.recipient-box * { text-decoration: none; }

/* Account box */
.account-box {
    border: 1px solid #000;
    padding: 5px 7px;
    text-align: center;
    margin: 8px 0;
    font-size: 11px;
    font-weight: bold;
}

/* Dates row */
.dates-row { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 9px; }
.dates-row td { vertical-align: top; padding: 2px 0; width: 25%; }
.dates-row .label { font-weight: normal; color: #444; font-size: 8px; display: block; }
.dates-row .value { font-weight: bold; }

/* Items table */
.items-table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 9px; }
.items-table th, .items-table td { border: 1px solid #000; padding: 3px 4px; }
.items-table th { text-align: center; font-weight: bold; background: #f2f2f2; }
.items-table td.num { text-align: right; white-space: nowrap; }

/* Total box */
.total-box { margin-top: 10px; text-align: right; font-size: 12px; font-weight: bold; }
.total-box span { border-top: 1px solid #000; padding-top: 3px; display: inline-block; min-width: 120px; text-align: right; }

/* Legal note */
.middle-note { margin-top: 10px; font-size: 8px; color: #555; }

/* Bottom row */
.bottom-row { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 9px; }
.bottom-row td { vertical-align: top; padding-top: 5px; }

/* VAT recap */
.vat-table { width: 100%; border-collapse: collapse; margin-top: 5px; }
.vat-table th, .vat-table td { border: 1px solid #000; padding: 2px 3px; text-align: right; white-space: nowrap; font-size: 8px; }
.vat-table th:first-child, .vat-table td:first-child { text-align: left; }
.vat-table th { background: #f2f2f2; }
.vat-table .sum-row td { font-weight: bold; background: #ebebeb; }

/* Signature box */
.sign-box { font-size: 9px; }
.sign-line { border-top: 1px solid #000; margin-top: 35px; text-align: center; font-size: 8px; padding-top: 2px; }
</style>
</head>
<body>

@php
    $priceTotal    = $invoice->price_total;
    $priceVatTotal = $invoice->price_vat_total;

    // VAT recap grouped by rate
    $vatRecap = [];
    foreach ($invoice->items as $item) {
        $rateKey = (string)(float)$item->vat;
        if (!isset($vatRecap[$rateKey])) {
            $vatRecap[$rateKey] = ['rate' => $item->vat, 'base' => 0.0, 'vat' => 0.0];
        }
        $base = round($item->price * $item->quantity, 2);
        $vatAmt = round($base * $item->vat, 2);
        $vatRecap[$rateKey]['base'] += $base;
        $vatRecap[$rateKey]['vat']  += $vatAmt;
    }

    $partner_addr = trim(($invoice->partner_street ?? '') . ' ' . ($invoice->partner_street_number ?? ''));
    $partner_city = trim(($invoice->partner_zip_code ?? '') . ' ' . ($invoice->partner_town ?? ''));
@endphp

{{-- TOP HEADER: supplier left, invoice title + recipient right --}}
<table class="top-row">
<tr>
    <td style="width:55%;">
        <div class="supplier-name">PVfree.net z.s.</div>
        <div>Daliborka 3<br>796 01 Prostějov</div>
        <br>
        <div>IČ: 26656787</div>
        <div>DIČ: CZ26656787</div>
        <div>Telefon: 588 207 234</div>
        <div>E-mail: rada@pvfree.net</div>
        <div>www.pvfree.net</div>
    </td>
    <td style="width:45%; text-align:right;">
        <div class="invoice-title">FAKTURA - DAŇOVÝ DOKLAD</div>
        <div class="invoice-number">č. {{ (int)$invoice->invoice_nr }}</div>

        <div class="vs-line">
            Variabilní symbol: <strong>{{ (int)$invoice->var_sym }}</strong>
        </div>

        <div class="recipient-box" style="text-align:left;">
            <h4>Odběratel:</h4>
            <p>{{ $invoice->partner_name }}</p>
            @if($partner_addr)
                <p>{{ $partner_addr }}</p>
            @endif
            @if($partner_city)
                <p>{{ $partner_city }}</p>
            @endif
            @if($invoice->organization_identifier)
                <p>IČO: {{ $invoice->organization_identifier }}</p>
            @endif
            @if($invoice->vat_organization_identifier)
                <p>DIČ: {{ $invoice->vat_organization_identifier }}</p>
            @endif
        </div>
    </td>
</tr>
</table>

{{-- ACCOUNT BOX --}}
<div class="account-box">
    Číslo účtu: {{ $invoice->account_nr }}
</div>

{{-- DATES ROW --}}
<table class="dates-row">
<tr>
    <td>
        <span class="label">Datum vystavení:</span>
        <span class="value">{{ $invoice->date_inv }}</span>
    </td>
    <td>
        <span class="label">Datum splatnosti:</span>
        <span class="value">{{ $invoice->date_due }}</span>
    </td>
    <td>
        <span class="label">Datum uskutečnění plnění:</span>
        <span class="value">{{ $invoice->date_vat }}</span>
    </td>
    <td>
        <span class="label">Forma úhrady:</span>
        <span class="value">Zaplaceno</span>
    </td>
</tr>
</table>

{{-- ITEMS TABLE --}}
<table class="items-table">
<thead>
<tr>
    <th style="text-align:left;">Označení dodávky</th>
    <th style="width:90px;">Cena</th>
    <th style="width:55px;">% DPH</th>
    <th style="width:75px;">DPH</th>
    <th style="width:85px;">Kč Celkem</th>
</tr>
</thead>
<tbody>
@forelse($invoice->items as $item)
@php
    $lineBase   = round($item->price * $item->quantity, 2);
    $lineVat    = round($lineBase * $item->vat, 2);
    $lineTotal  = round($lineBase + $lineVat, 2);
@endphp
<tr>
    <td>{{ $item->name }}</td>
    <td class="num">{{ number_format($lineBase, 2, ',', ' ') }} CZK</td>
    <td class="num">{{ number_format($item->vat * 100, 0) }}%</td>
    <td class="num">{{ number_format($lineVat, 2, ',', ' ') }} CZK</td>
    <td class="num">{{ number_format($lineTotal, 2, ',', ' ') }} CZK</td>
</tr>
@empty
<tr><td colspan="5" style="text-align:center;">Faktura neobsahuje žádné položky.</td></tr>
@endforelse
</tbody>
</table>

{{-- CELKEM ZAPLACENO --}}
<div class="total-box">
    CELKEM ZAPLACENO: &nbsp;<span>{{ number_format($priceVatTotal, 2, ',', ' ') }} CZK</span>
</div>

{{-- LEGAL NOTE --}}
<div class="middle-note">
    Spolek PVfree.net, z.s., zapsán pod značkou L 10341/KSBR Krajským soudem v Brně, založen 12.3.2004.
</div>

{{-- BOTTOM: QR placeholder | VAT recap | Signature --}}
<table class="bottom-row">
<tr>
    <td style="width:40%;"></td>

    <td style="width:35%; padding-left:10px;">
        <div><strong>Rekapitulace DPH v Kč:</strong></div>
        <table class="vat-table">
        <thead>
        <tr>
            <th>Základ v Kč</th>
            <th>Sazba</th>
            <th>DPH v Kč</th>
            <th>Celkem s DPH v Kč</th>
        </tr>
        </thead>
        <tbody>
        @forelse($vatRecap as $rec)
        <tr>
            <td>{{ number_format($rec['base'], 2, ',', ' ') }}</td>
            <td style="text-align:center;">{{ number_format($rec['rate'] * 100, 0) }}%</td>
            <td>{{ number_format($rec['vat'], 2, ',', ' ') }}</td>
            <td>{{ number_format($rec['base'] + $rec['vat'], 2, ',', ' ') }}</td>
        </tr>
        @empty
        <tr><td colspan="4" style="text-align:center;">Bez DPH.</td></tr>
        @endforelse
        </tbody>
        </table>
    </td>

    <td style="width:25%; padding-left:15px;"></td>
</tr>
</table>

</body>
</html>
