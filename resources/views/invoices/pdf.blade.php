<?php
// Variables passed in from InvoiceService::generatePdf():
// $invoice  - Invoice model
// $org      - Member model (organization)
// $items    - invoice items collection
// $bankAccount - BankAccount model

$inv = $invoice;
$vs  = $inv->var_sym ?? '';
$acc = $inv->account_nr ?? '';

// Dates in Czech format
$date_inv = $inv->date_inv ? \Carbon\Carbon::parse($inv->date_inv)->format('d.m.Y') : '';
$date_due = $inv->date_due ? \Carbon\Carbon::parse($inv->date_due)->format('d.m.Y') : '';
$date_vat = $inv->date_vat ? \Carbon\Carbon::parse($inv->date_vat)->format('d.m.Y') : '';

// Format money helper
$format_money = fn($val) => number_format((float)$val, 2, ',', ' ') . ' CZK';

// Build items with VAT calculated
$items_calculated = [];
$vat_totals = [];
$total_net = 0;
$total_vat = 0;

foreach ($items as $item) {
    $net       = (float)$item->price * (int)($item->quantity ?? 1);
    $vat_rate  = (float)($item->vat ?? 0);
    $vat_value = round($net * $vat_rate, 2);
    $total     = $net + $vat_value;

    $items_calculated[] = [
        'name'      => $item->name,
        'net'       => $net,
        'vat_rate'  => $vat_rate,
        'vat_value' => $vat_value,
        'total'     => $total,
    ];

    // Položka "Zaokrouhlení" (code ROUND, vat=0) musí být v itemech kvůli součtu
    // s původní platbou, ale do rekapitulace DPH nepatří — není to zdanitelné
    // plnění, jen halířové vyrovnání.
    if ((string)($item->code ?? '') !== 'ROUND' && $vat_rate > 0) {
        $rate_key = (string)$vat_rate;
        if (!isset($vat_totals[$rate_key])) {
            $vat_totals[$rate_key] = ['base' => 0, 'vat' => 0, 'rate' => $vat_rate];
        }
        $vat_totals[$rate_key]['base'] += $net;
        $vat_totals[$rate_key]['vat']  += $vat_value;
    }

    $total_net += $net;
    $total_vat += $vat_value;
}
?>
<!doctype html>
<html lang="cs">

<head>
    <meta charset="utf-8">
    <title>Faktura</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
        }

        .page {
            width: 210mm;
            height: 297mm;
            margin: 0 auto;
            /* vystředění při náhledu */
            padding: 15mm;
            /* vnitřní okraje faktury */
            border: 1px solid #000;
            box-sizing: border-box;
        }


        .invoice {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            padding: 15px 20px 20px;
        }

        table.page-frame {
            width: 100%;
            /* 100 % šířky tiskové plochy (A4) */
            border: 1px solid #000;
            /* rámeček dokola */
            border-collapse: collapse;
        }

        table.page-frame td {
            padding: 10mm;
            /* vnitřní okraj od rámečku */
            height: 277mm;
            /* 297 - 2×10mm padding = „plná“ výška */
            box-sizing: border-box;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            vertical-align: top;
        }

        .top-row {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .top-row td {
            vertical-align: top;
        }

        .supplier-name {
            font-weight: bold;
            font-size: 17px;
            margin-bottom: 5px;
        }

        .invoice-title {
            text-align: right;
            font-weight: bold;
            font-size: 17px;
            text-transform: uppercase;
        }

        .invoice-number {
            text-align: right;
            font-weight: bold;
            margin-top: 3px;
        }


        .box {

            padding: 5px 7px;
            margin-top: 6px;
            font-size: 12px;
        }

        .box-inner {
            padding: 3px 5px;
        }

        .box p {
            margin: 0;
            padding: 0;
            text-decoration: none;
        }

        .box h4 {
            margin: 0 0 3px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .inline-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .inline-table td {
            padding: 1px 0;
        }

        .account-box {
            border: 1px solid #000;
            padding: 5px 7px;
            text-align: center;
            margin: 10px 0;
            font-size: 13px;
            font-weight: bold;
        }

        .dates-row {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .dates-row td {
            vertical-align: top;
            padding: 2px 0;
        }

        .dates-row .col {
            width: 25%;
        }

        .dates-row .label {
            font-weight: normal;
            width: auto;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 12px;
        }

        .items-table th,
        .items-table td {
            border: 1px solid #000;
            padding: 3px 4px;
        }

        .items-table th {
            text-align: center;
            font-weight: bold;
            background: #f2f2f2;
        }

        .items-table td.num {
            text-align: right;
            white-space: nowrap;
        }

        .items-table tfoot td {
            font-weight: bold;
        }

        .total-box {
            margin-top: 10px;
            text-align: right;
            font-size: 14px;
            font-weight: bold;
        }

        .total-box span {
            border-top: 1px solid #000;
            padding-top: 3px;
            display: inline-block;
            min-width: 120px;
            text-align: right;
        }

        .middle-note {
            margin-top: 10px;
            font-size: 11px;
        }

        .bottom-row {
            margin-top: 15px;
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        .bottom-row td {
            vertical-align: top;
            padding-top: 5px;
        }

        .qr-box {
            width: 40%;
        }

        .qr-placeholder {
            width: 70mm;
            height: 70mm;
            border: 1px solid #000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            margin-top: 5px;
        }

        .vat-box {
            width: 35%;
            padding-left: 10px;
        }

        .vat-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }

        .vat-table th,
        .vat-table td {
            border: 1px solid #000;
            padding: 2px 3px;
            text-align: right;
            white-space: nowrap;
        }

        .vat-table th:first-child,
        .vat-table td:first-child {
            text-align: left;
        }

        .sign-box {
            width: 25%;
            padding-left: 15px;
            font-size: 12px;
        }

        .sign-box .label {
            font-weight: bold;
            margin-bottom: 30px;
        }

        .sign-line {
            border-top: 1px solid #000;
            margin-top: 35px;
            text-align: center;
            font-size: 11px;
            padding-top: 2px;
        }

        .footer {
            margin-top: 10px;
            font-size: 10px;
        }
    </style>
</head>

<body>

    <div class="page">
        <div class="invoice">

            <!-- Horní část: dodavatel / název faktury -->
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
                        <div class="invoice-title">FAKTURA - DAŇOVÝ DOKLAD </div>
                        <div class="invoice-title">č. <?= htmlspecialchars($inv->invoice_nr) ?></div>

                        <div class="box" style="margin-top:10px; text-align:right;">
                            Variabilní symbol: <span style="display:inline-block; min-width:70px; text-align:left;"><?= htmlspecialchars($vs) ?></span>
                        </div>


                        <div class="box">
                            <div class="box-inner">
                                <h4>Odběratel:</h4>
                                <p><?= htmlspecialchars($inv->partner_name) ?></p>
                                <div>
                                    <?php if ($inv->partner_street || $inv->partner_street_number): ?>
                                        <p>
                                            <?= htmlspecialchars(trim($inv->partner_street . ' ' . $inv->partner_street_number)) ?>
                                        </p>
                                    <?php endif; ?>
                                    <?php if ($inv->partner_zip_code || $inv->partner_town): ?>
                                        <p><?= htmlspecialchars(trim($inv->partner_zip_code . ' ' . $inv->partner_town)) ?></p>
                                    <?php endif; ?>
                                    <?php if ($inv->organization_identifier): ?>
                                        <p>IČO: <?= htmlspecialchars($inv->organization_identifier) ?></p>
                                    <?php endif; ?>
                                    <?php if ($inv->vat_organization_identifier): ?>
                                        <p>DIČ: <?= htmlspecialchars($inv->vat_organization_identifier) ?></p>
                                    <?php endif; ?>
                                </div>

                            </div>
                        </div>
                    </td>
                </tr>
            </table>

            <!-- Účet -->
            <div class="account-box">
                Číslo účtu: <?= htmlspecialchars($acc) ?>
            </div>

            <!-- Datum a forma úhrady -->
            <table class="dates-row">
                <tr>
                    <td class="col">
                        <span class="label">Datum vystavení:</span><br>
                        <?= htmlspecialchars($date_inv) ?>
                    </td>
                    <td class="col">
                        <span class="label">Datum splatnosti:</span><br>
                        <?= htmlspecialchars($date_inv) ?>
                    </td>
                    <td class="col">
                        <span class="label">Datum uskutečnění plnění:</span><br>
                        <?= htmlspecialchars($date_vat) ?>
                    </td>
                    <td class="col">
                        <span class="label">Forma úhrady:</span><br>
                        Zaplaceno
                    </td>
                </tr>
            </table>
            <!-- Položky -->
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
                    <?php if (empty($items_calculated)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center;">Faktura neobsahuje žádné položky.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items_calculated as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['name']) ?></td>
                                <td class="num"><?= $format_money($item['net']) ?></td>
                                <td class="num"><?= number_format($item['vat_rate'] * 100, 0) ?>%</td>
                                <td class="num"><?= $format_money($item['vat_value']) ?></td>
                                <td class="num"><?= $format_money($item['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>

                </tbody>
            </table>

            <!-- Celkem k úhradě -->
            <div class="total-box">
                CELKEM ZAPLACENO: &nbsp; <?= $format_money($total_net + $total_vat) ?>
            </div>


            <!-- Vystavil -->
            <div style="margin-top:8px; font-size:12px;">
            </div>

            <!-- Text pod tabulkou -->
            <div class="middle-note">
                Spolek PVfree.net, z.s., založen 12.3.2004, zapsán pod značkou L 10341/KSBR Krajským soudem v Brně.<br>

            </div>

            <!-- Spodní část: QR + rekapitulace DPH + razítko -->
            <table class="bottom-row">
                <tr>
                    <td class="qr-box">
                    </td>

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
                                <?php if (empty($vat_totals)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align:center;">Bez DPH.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($vat_totals as $rate => $values): ?>
                                        <tr>
                                            <td class="num"><?= $format_money($values['base']) ?></td>
                                            <td> <?= rtrim(rtrim(number_format($values['rate'] * 100, 2, ',', ''), '0'), ',') ?>%</td>
                                            <td class="num"><?= $format_money($values['vat']) ?></td>
                                            <td class="num"><?= $format_money($values['base'] + $values['vat']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </td>

                </tr>
            </table>

            <div class="footer">
            </div>

        </div>
    </div>
</body>

</html>