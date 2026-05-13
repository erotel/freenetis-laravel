@php
    // $doc = ['doc_number', 'variable_symbol', 'name', 'address', 'ico', 'dic',
    //         'member_type' (2/90), 'leaving_date' (Y-m-d), 'amount', 'currency', 'refund_account']
    $isCustomer = ((int) $doc['member_type']) === 2;

    $titleItem = $isCustomer
        ? 'Fakturujeme Vám na základě vyúčtování - přeplatek za "Platba za připojení k síti internet" - ukončeno k'
        : 'Fakturujeme Vám na základě vyúčtování - přeplatek za "Členství" - ukončeno k';

    $leavingDateFmt = '';
    if (!empty($doc['leaving_date'])) {
        $d = DateTime::createFromFormat('Y-m-d', $doc['leaving_date']);
        if ($d) $leavingDateFmt = $d->format('j.n.Y');
    }

    $amountWithVat    = (float) $doc['amount'];
    $vatRate          = 21;
    $amountWithoutVat = round($amountWithVat / (1 + $vatRate / 100), 2);
    $vatAmount        = round($amountWithVat - $amountWithoutVat, 2);

    $issueDateFmt = (new DateTime('today'))->format('j.n.Y');
    $dueDateFmt   = (new DateTime('today'))->modify('+14 days')->format('j.n.Y');

    $fmt = fn($val) => number_format((float) $val, 2, ',', ' ');
    $neg = fn($val) => '-' . number_format(abs((float) $val), 2, ',', ' ');
@endphp
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<title>Vratka {{ $doc['doc_number'] }}</title>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; padding: 0; }
    .page { width: 210mm; height: 297mm; margin: 0 auto; padding: 15mm; border: 1px solid #000; box-sizing: border-box; }
    .invoice { width: 100%; max-width: 800px; margin: 0 auto; padding: 15px 20px 20px; }
    .top-row { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .top-row td { vertical-align: top; }
    .supplier-name { font-weight: bold; font-size: 14px; margin-bottom: 5px; }
    .invoice-title { text-align: right; font-weight: bold; font-size: 14px; text-transform: uppercase; }
    .box { padding: 5px 7px; margin-top: 6px; font-size: 10px; }
    .box-inner { padding: 3px 5px; }
    .box p { margin: 0; padding: 0; text-decoration: none; }
    .box h4 { margin: 0 0 3px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
    .account-box { border: 1px solid #000; padding: 5px 7px; text-align: center; margin: 10px 0; font-size: 11px; font-weight: bold; }
    .dates-row { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 10px; }
    .dates-row td { vertical-align: top; padding: 2px 0; }
    .dates-row .col { width: 25%; }
    .dates-row .label { font-weight: normal; }
    .items-table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 10px; }
    .items-table th, .items-table td { border: 1px solid #000; padding: 3px 4px; }
    .items-table th { text-align: center; font-weight: bold; background: #f2f2f2; }
    .items-table td.num { text-align: right; white-space: nowrap; }
    .total-box { margin-top: 10px; text-align: right; font-size: 12px; font-weight: bold; }
    .middle-note { margin-top: 10px; font-size: 9px; }
    .bottom-row { margin-top: 15px; width: 100%; border-collapse: collapse; font-size: 9px; }
    .bottom-row td { vertical-align: top; padding-top: 5px; }
    .vat-box { width: 35%; padding-left: 10px; }
    .vat-table { width: 100%; border-collapse: collapse; margin-top: 5px; }
    .vat-table th, .vat-table td { border: 1px solid #000; padding: 2px 3px; text-align: right; white-space: nowrap; }
    .vat-table th:first-child, .vat-table td:first-child { text-align: left; }
</style>
</head>
<body>
<div class="page">
<div class="invoice">

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
                <div class="invoice-title">č. {{ $doc['doc_number'] }}</div>

                <div class="box" style="margin-top:10px; text-align:right;">
                    Variabilní symbol:
                    <span style="display:inline-block; min-width:70px; text-align:left;">{{ $doc['variable_symbol'] }}</span>
                </div>

                <div class="box">
                    <div class="box-inner">
                        <h4>Odběratel:</h4>
                        <p>{{ $doc['name'] }}</p>
                        <div>
                            @if(trim($doc['address']) !== '')
                                @foreach(explode("\n", $doc['address']) as $line)
                                    <p>{{ $line }}</p>
                                @endforeach
                            @endif
                            @if(!empty($doc['ico'])) IČO: {{ $doc['ico'] }} @endif
                            @if(!empty($doc['ico']) && !empty($doc['dic']))&nbsp;&nbsp;@endif
                            @if(!empty($doc['dic'])) DIČ: {{ $doc['dic'] }} @endif
                        </div>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <div class="account-box"></div>

    <table class="dates-row">
        <tr>
            <td class="col"><span class="label">Datum vystavení:</span><br>{{ $issueDateFmt }}</td>
            <td class="col"><span class="label">Datum splatnosti:</span><br>{{ $dueDateFmt }}</td>
            <td class="col"><span class="label">Datum uskutečnění plnění:</span><br>{{ $leavingDateFmt }}</td>
            <td class="col"><span class="label">Forma úhrady:</span><br>Převodem</td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th>Označení dodávky</th>
                <th style="width:90px;">Cena</th>
                <th style="width:90px;">% DPH</th>
                <th style="width:70px;">DPH</th>
                <th style="width:80px;">Kč Celkem</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $titleItem }} {{ $leavingDateFmt }}</td>
                <td class="num">{{ $neg($amountWithoutVat) }}</td>
                <td class="num">21 %</td>
                <td class="num">{{ $neg($vatAmount) }}</td>
                <td class="num">{{ $neg($amountWithVat) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="total-box">
        CELKEM K ÚHRADĚ: &nbsp; {{ $neg($amountWithVat) }} {{ $doc['currency'] }}
    </div>

    <div class="middle-note">
        Vzniklý přeplatek ve výši {{ $fmt($amountWithVat) }} {{ $doc['currency'] }} Vám bude vrácen na účet {{ $doc['refund_account'] }}<br><br>
        Spolek PVfree.net, z.s., založen 12.3.2004, zapsán pod značkou L 10341/KSBR Krajským soudem v Brně.<br>
    </div>

    <table class="bottom-row">
        <tr>
            <td style="width:65%"></td>
            <td class="vat-box">
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
                        <tr>
                            <td class="num">{{ $neg($amountWithoutVat) }}</td>
                            <td class="num">21 %</td>
                            <td class="num">{{ $neg($vatAmount) }}</td>
                            <td class="num">{{ $neg($amountWithVat) }}</td>
                        </tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

</div>
</div>
</body>
</html>
