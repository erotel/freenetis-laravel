<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class PohodaExportService
{
    private string $exportDir;

    public function __construct()
    {
        $this->exportDir = storage_path('app/private/pohoda-exports/');
    }

    public function exportInvoices(int $year, int $month): ?string
    {
        // Idempotentní výběr: bereme všechno status='new' s date_inv do konce
        // cílového měsíce (včetně zapomenutých faktur z dřívějších měsíců).
        // Cap zabrání tomu, aby export za květen pochytil i červnové faktury,
        // které mezitím vygeneroval DeductFees 1. v měsíci. Po úspěšném
        // vyrobení XML celou várku překlopíme na 'exported'.
        $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, (int) date('t', mktime(0, 0, 0, $month, 1, $year)));

        $invoices = DB::table('invoices')
            ->where('pohoda_status', 'new')
            ->whereDate('date_inv', '<=', $monthEnd)
            ->orderBy('invoice_nr')
            ->get();

        if ($invoices->isEmpty()) {
            return null;
        }

        $exportedIds = $invoices->pluck('id')->all();

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
            $items = DB::table('invoice_items')
                ->where('invoice_id', $inv->id)
                ->orderBy('id')
                ->get();

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

        $filename = $this->exportDir . sprintf('pohoda_%04d_%02d.xml', $year, $month);
        is_dir($this->exportDir) || mkdir($this->exportDir, 0755, true);
        file_put_contents($filename, $xml->outputMemory());

        DB::table('invoices')
            ->whereIn('id', $exportedIds)
            ->update([
                'pohoda_status'      => 'exported',
                'pohoda_exported_at' => now(),
            ]);

        return $filename;
    }

    public function exportRefunds(int $year, int $month): ?string
    {
        // Cap stejně jako u faktur — created_at do konce cílového měsíce,
        // aby export za květen nepochytil červnové vratky.
        $monthEnd = sprintf('%04d-%02d-%02d 23:59:59', $year, $month, (int) date('t', mktime(0, 0, 0, $month, 1, $year)));

        $items = DB::table('pohoda_refund_queue as q')
            ->join('members as m', 'm.id', '=', 'q.member_id')
            ->leftJoin('address_points as ap', 'ap.id', '=', 'm.address_point_id')
            ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
            ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
            ->where('q.status', 'new')
            ->where('q.created_at', '<=', $monthEnd)
            ->orderBy('q.id')
            ->limit(500)
            ->select(
                'q.*',
                'm.name as member_name',
                'm.organization_identifier',
                'm.vat_organization_identifier',
                's.street',
                'ap.street_number',
                't.town',
                't.zip_code'
            )
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        $ico         = Setting::get('ico', '');
        $application = Setting::get('pohoda_application', 'Freenetis');
        $now         = date('Y-m-d_H-i-s');

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElementNs('dat', 'dataPack', 'http://www.stormware.cz/schema/version_2/data.xsd');
        $xml->writeAttribute('id', 'ref_' . $now);
        $xml->writeAttribute('ico', $ico);
        $xml->writeAttribute('application', $application);
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('note', 'Imported from Freenetis');
        $xml->writeAttributeNs('xmlns', 'inv', null, 'http://www.stormware.cz/schema/version_2/invoice.xsd');
        $xml->writeAttributeNs('xmlns', 'typ', null, 'http://www.stormware.cz/schema/version_2/type.xsd');

        $ids = [];
        foreach ($items as $item) {
            $ids[] = $item->id;

            $xml->startElementNs('dat', 'dataPackItem', null);
            $xml->writeAttribute('id', 'DP' . $item->doc_number);
            $xml->writeAttribute('version', '2.0');

            $xml->startElementNs('inv', 'invoice', null);
            $xml->writeAttribute('version', '2.0');

            $xml->startElementNs('inv', 'invoiceHeader', null);
            $xml->writeElementNs('inv', 'invoiceType', null, 'creditNote');

            $xml->startElementNs('inv', 'number', null);
            $xml->writeElementNs('typ', 'numberRequested', null, (string)$item->doc_number);
            $xml->endElement();

            $xml->writeElementNs('inv', 'date', null, date('Y-m-d'));
            $xml->writeElementNs('inv', 'note', null, (string)($item->note ?? $item->reason ?? ''));

            $xml->startElementNs('inv', 'partnerIdentity', null);
            $xml->startElementNs('typ', 'address', null);
            $icoPartner = trim((string)($item->organization_identifier ?? ''));
            if ($icoPartner !== '') {
                $xml->writeElementNs('typ', 'company', null, (string)($item->member_name ?? ''));
                $xml->writeElementNs('typ', 'name',    null, '');
                $xml->writeElementNs('typ', 'ico',     null, $icoPartner);
            } else {
                $xml->writeElementNs('typ', 'company', null, '');
                $xml->writeElementNs('typ', 'name',    null, mb_substr((string)($item->member_name ?? ''), 0, 32, 'UTF-8'));
            }
            $street = trim(($item->street ?? '') . ' ' . ($item->street_number ?? ''));
            $xml->writeElementNs('typ', 'street', null, $street);
            $xml->writeElementNs('typ', 'city',   null, (string)($item->town     ?? ''));
            $xml->writeElementNs('typ', 'zip',    null, (string)($item->zip_code ?? ''));
            $xml->startElementNs('typ', 'country', null);
            $xml->writeElementNs('typ', 'ids', null, 'Czech Republic');
            $xml->endElement(); // typ:country
            $xml->endElement(); // typ:address
            $xml->endElement(); // inv:partnerIdentity

            $xml->endElement(); // inv:invoiceHeader

            $amount = (float)($item->amount ?? 0);
            $xml->startElementNs('inv', 'invoiceDetail', null);
            $xml->startElementNs('inv', 'invoiceItem', null);
            $xml->writeElementNs('inv', 'text',     null, (string)($item->reason ?? 'Vrácení'));
            $xml->writeElementNs('inv', 'quantity', null, '1');
            $xml->writeElementNs('inv', 'rateVAT',  null, 'none');
            $xml->startElementNs('inv', 'homeCurrency', null);
            $xml->writeElementNs('typ', 'unitPrice', null, $this->fmt($amount));
            $xml->writeElementNs('typ', 'price',     null, $this->fmt($amount));
            $xml->writeElementNs('typ', 'priceVAT',  null, '0');
            $xml->writeElementNs('typ', 'priceSum',  null, (string)round($amount));
            $xml->endElement(); // homeCurrency
            $xml->endElement(); // invoiceItem
            $xml->endElement(); // invoiceDetail

            $xml->endElement(); // inv:invoice
            $xml->endElement(); // dat:dataPackItem
        }

        $xml->endElement(); // dat:dataPack
        $xml->endDocument();

        $filename = $this->exportDir . sprintf('pohoda_refunds_%04d_%02d_%s.xml', $year, $month, date('His'));
        is_dir($this->exportDir) || mkdir($this->exportDir, 0755, true);
        file_put_contents($filename, $xml->outputMemory());

        DB::table('pohoda_refund_queue')
            ->whereIn('id', $ids)
            ->update(['status' => 'exported', 'exported_at' => now()]);

        return $filename;
    }

    /** Format number: trim trailing zeros, dot as decimal separator. */
    private function fmt(float $n): string
    {
        $s = number_format($n, 2, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
