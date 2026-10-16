<?php

namespace App\Domains\Billing\Documents;

use App\Models\Setting;

/**
 * XML vydaných faktur pro import do Pohody (dataPack stormware version_2).
 * Položky: DPH high (≥ 20 %) / low / none; součet položky zaokrouhlen na koruny,
 * souhrn jen za sazbu high (math2one). Partner: s IČO firma + IČO/DIČ, jinak
 * jméno oříznuté na 32 znaků.
 */
class PohodaInvoicesXml
{
    /**
     * @param iterable<object> $invoices řádky invoices
     * @param \Closure(int $invoiceId): iterable<object> $itemsByInvoice položky faktury
     */
    public function build(iterable $invoices, \Closure $itemsByInvoice): string
    {
        $ico         = Setting::get('ico', '');
        $application = Setting::get('pohoda_application', 'Freenetis');
        $now         = date('Y-m-d_H-i-s');

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElementNs('dat', 'dataPack', 'http://www.stormware.cz/schema/version_2/data.xsd');
        $xml->writeAttribute('id', 'inv_' . $now);
        $xml->writeAttribute('ico', $ico);
        $xml->writeAttribute('application', $application);
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('note', 'Imported from Freenetis');
        $xml->writeAttributeNs('xmlns', 'inv', null, 'http://www.stormware.cz/schema/version_2/invoice.xsd');
        $xml->writeAttributeNs('xmlns', 'typ', null, 'http://www.stormware.cz/schema/version_2/type.xsd');

        foreach ($invoices as $inv) {
            $items = $itemsByInvoice($inv->id);

            $xml->startElementNs('dat', 'dataPackItem', null);
            $xml->writeAttribute('id', 'DP' . $inv->invoice_nr);
            $xml->writeAttribute('version', '2.0');

            $xml->startElementNs('inv', 'invoice', null);
            $xml->writeAttribute('version', '2.0');

            // invoiceHeader
            $xml->startElementNs('inv', 'invoiceHeader', null);
            $xml->writeElementNs('inv', 'invoiceType', null, 'issuedInvoice');

            $xml->startElementNs('inv', 'number', null);
            $xml->writeElementNs('typ', 'numberRequested', null, (string)$inv->invoice_nr);
            $xml->endElement();

            $xml->writeElementNs('inv', 'symVar', null, (string)($inv->var_sym ?? ''));
            if (!empty($inv->date_inv))  $xml->writeElementNs('inv', 'date',    null, $inv->date_inv);
            if (!empty($inv->date_vat))  $xml->writeElementNs('inv', 'dateTax', null, $inv->date_vat);
            if (!empty($inv->date_due))  $xml->writeElementNs('inv', 'dateDue', null, $inv->date_due);

            // partnerIdentity
            $xml->startElementNs('inv', 'partnerIdentity', null);
            $xml->startElementNs('typ', 'address', null);

            $partnerName = (string)($inv->partner_name ?? '');
            $icoPartner  = trim((string)($inv->organization_identifier ?? ''));
            if ($icoPartner !== '') {
                $xml->writeElementNs('typ', 'company', null, $partnerName);
                $xml->writeElementNs('typ', 'name',    null, '');
                $xml->writeElementNs('typ', 'ico',     null, $icoPartner);
                $dic = trim((string)($inv->vat_organization_identifier ?? ''));
                if ($dic !== '') {
                    $xml->writeElementNs('typ', 'dic', null, $dic);
                }
            } else {
                $xml->writeElementNs('typ', 'company', null, '');
                $xml->writeElementNs('typ', 'name',    null, mb_substr($partnerName, 0, 32, 'UTF-8'));
            }

            $street = trim(($inv->partner_street ?? '') . ' ' . ($inv->partner_street_number ?? ''));
            $xml->writeElementNs('typ', 'city',   null, (string)($inv->partner_town     ?? ''));
            $xml->writeElementNs('typ', 'street', null, $street);
            $xml->writeElementNs('typ', 'zip',    null, (string)($inv->partner_zip_code ?? ''));
            $xml->startElementNs('typ', 'country', null);
            $xml->writeElementNs('typ', 'ids', null, 'Czech Republic');
            $xml->endElement(); // typ:country
            $xml->writeElementNs('typ', 'phone', null, (string)($inv->phone_number ?? ''));
            $xml->writeElementNs('typ', 'email', null, (string)($inv->email         ?? ''));

            $xml->endElement(); // typ:address
            $xml->endElement(); // inv:partnerIdentity

            $xml->writeElementNs('inv', 'numberOrder', null, '');
            $xml->writeElementNs('inv', 'symConst',    null, '');
            $xml->writeElementNs('inv', 'note',        null, (string)($inv->note ?? ''));
            $xml->endElement(); // inv:invoiceHeader

            // invoiceDetail
            $xml->startElementNs('inv', 'invoiceDetail', null);
            $sumHighBase = 0.0;
            $sumHighVat  = 0.0;

            foreach ($items as $item) {
                $vatRate = (float)($item->vat ?? 0);
                if ($vatRate > 0 && $vatRate <= 1.0) {
                    $vatRate *= 100.0;
                }
                $qty      = max((float)($item->quantity ?? 1), 0.0001);
                $price    = (float)($item->price ?? 0);
                $base     = round($price * $qty, 2);
                $vatAmt   = round($base * ($vatRate / 100.0), 2);
                $sumItem  = round($base + $vatAmt);
                $rateVat  = $vatRate >= 20.0 ? 'high' : ($vatRate > 0.0 ? 'low' : 'none');

                if ($rateVat === 'high') {
                    $sumHighBase += $base;
                    $sumHighVat  += $vatAmt;
                }

                $xml->startElementNs('inv', 'invoiceItem', null);
                $xml->writeElementNs('inv', 'text',     null, (string)($item->name ?? ''));
                $xml->writeElementNs('inv', 'quantity', null, $this->fmt($qty));
                $xml->writeElementNs('inv', 'rateVAT',  null, $rateVat);

                $xml->startElementNs('inv', 'homeCurrency', null);
                $xml->writeElementNs('typ', 'unitPrice', null, $this->fmt($price));
                $xml->writeElementNs('typ', 'price',     null, $this->fmt($base));
                $xml->writeElementNs('typ', 'priceVAT',  null, $this->fmt($vatAmt));
                $xml->writeElementNs('typ', 'priceSum',  null, (string)$sumItem);
                $xml->endElement(); // homeCurrency

                $xml->writeElementNs('inv', 'code', null, (string)($item->code ?? ''));
                $xml->endElement(); // invoiceItem
            }
            $xml->endElement(); // invoiceDetail

            // invoiceSummary
            $highBase = round($sumHighBase, 2);
            $highVat  = round($sumHighVat, 2);
            $highSum  = round($highBase + $highVat);

            $xml->startElementNs('inv', 'invoiceSummary', null);
            $xml->writeElementNs('inv', 'roundingDocument', null, 'math2one');
            $xml->startElementNs('inv', 'homeCurrency', null);
            $xml->writeElementNs('typ', 'priceNone',     null, '0');
            $xml->writeElementNs('typ', 'priceLow',      null, '0');
            $xml->writeElementNs('typ', 'priceLowVAT',   null, '0');
            $xml->writeElementNs('typ', 'priceLowSum',   null, '0');
            $xml->writeElementNs('typ', 'priceHigh',     null, $this->fmt($highBase));
            $xml->writeElementNs('typ', 'priceHighVAT',  null, $this->fmt($highVat));
            $xml->writeElementNs('typ', 'priceHighSum',  null, (string)$highSum);
            $xml->startElementNs('typ', 'round', null);
            $xml->writeElementNs('typ', 'priceRound', null, '0');
            $xml->endElement(); // typ:round
            $xml->endElement(); // homeCurrency
            $xml->endElement(); // invoiceSummary

            $xml->endElement(); // inv:invoice
            $xml->endElement(); // dat:dataPackItem
        }

        $xml->endElement(); // dat:dataPack
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /** Číslo: bez koncových nul, tečka jako desetinný oddělovač. */
    private function fmt(float $n): string
    {
        $s = number_format($n, 2, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
