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
            ->leftJoin('countries as c', 'c.id', '=', 'ap.country_id')
            ->where('q.status', 'new')
            ->where('q.created_at', '<=', $monthEnd)
            ->orderBy('q.id')
            ->limit(500)
            ->select(
                'q.*',
                'm.name as member_name',
                'm.organization_identifier as member_ico',
                'm.vat_organization_identifier as member_dic',
                's.street as addr_street',
                'ap.street_number as addr_street_number',
                't.town as addr_town',
                't.quarter as addr_quarter',
                't.zip_code as addr_zip',
                'c.country_name as addr_country'
            )
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        $xmlString = $this->buildRefundXml($items);
        $ids       = $items->pluck('id')->all();

        $filename = $this->exportDir . sprintf('pohoda_refunds_%04d_%02d_%s.xml', $year, $month, date('His'));
        is_dir($this->exportDir) || mkdir($this->exportDir, 0755, true);
        file_put_contents($filename, $xmlString);

        DB::table('pohoda_refund_queue')
            ->whereIn('id', $ids)
            ->update(['status' => 'exported', 'exported_at' => now()]);

        return $filename;
    }

    /**
     * Vygeneruje POHODA XML pro vratky (vydaný opravný daňový doklad).
     * Port Kohana modelu Pohoda_Refund_Export_Model::build_xml — DPH 21 %,
     * částka v DB kladná, do XML záporně. Jediný správný formát, který Pohoda přijme.
     */
    public function buildRefundXml($items): string
    {
        $ico = trim((string) Setting::get('ico', ''));
        if ($ico === '') {
            throw new \RuntimeException('POHODA export: chybí povinné IČO sdružení v config.ico.');
        }

        $ns_dat = 'http://www.stormware.cz/schema/version_2/data.xsd';
        $ns_inv = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
        $ns_typ = 'http://www.stormware.cz/schema/version_2/type.xsd';

        $xml = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="utf-8"?>'
            . '<dat:dataPack'
            . ' xmlns:dat="' . $ns_dat . '"'
            . ' xmlns:inv="' . $ns_inv . '"'
            . ' xmlns:typ="' . $ns_typ . '"'
            . '/>'
        );
        $xml->addAttribute('version', '2.0');
        $xml->addAttribute('id', 'FreenetISRefunds');
        $xml->addAttribute('application', 'FreenetIS');
        $xml->addAttribute('note', 'Refund export (Dobropisy)');
        $xml->addAttribute('ico', $ico);

        $today = date('Y-m-d');

        foreach ($items as $r) {
            $amountWithVat = (float) ($r->amount ?? 0);
            if ($amountWithVat <= 0) continue;

            $vatRate = 21.0;
            $basePos = round($amountWithVat / (1.0 + $vatRate / 100.0), 2);
            $vatPos  = round($amountWithVat - $basePos, 2);
            $sumPos  = round($amountWithVat, 2);

            $base = -$basePos;
            $vat  = -$vatPos;
            $sum  = -$sumPos;

            $dpi = $xml->addChild('dat:dataPackItem', null, $ns_dat);
            $dpi->addAttribute('version', '2.0');
            $dpi->addAttribute('id', 'RFQ_' . (int) $r->id);

            $inv = $dpi->addChild('inv:invoice', null, $ns_inv);
            $inv->addAttribute('version', '2.0');

            $hdr = $inv->addChild('inv:invoiceHeader', null, $ns_inv);
            $hdr->addChild('inv:invoiceType', 'issuedCorrectiveTax', $ns_inv);

            $docNumber = trim((string) ($r->doc_number ?? ''));
            if ($docNumber === '') $docNumber = 'RFQ-' . (int) $r->id;

            $num = $hdr->addChild('inv:number', null, $ns_inv);
            $num->addChild('typ:numberRequested', $this->xmlText($docNumber), $ns_typ);

            $created = $this->dateYmd((string) ($r->created_at ?? '')) ?: $today;
            $hdr->addChild('inv:date',           $created, $ns_inv);
            $hdr->addChild('inv:dateTax',        $created, $ns_inv);
            $hdr->addChild('inv:dateAccounting', $created, $ns_inv);

            $symVar = preg_replace('~\D+~', '', $docNumber) ?: (string) (int) $r->id;
            $hdr->addChild('inv:symVar', $this->xmlText($symVar), $ns_inv);

            $member = trim((string) ($r->member_name ?? '')) ?: 'Neznámý odběratel';
            $icoMember = preg_replace('~\D+~', '', trim((string) ($r->member_ico ?? '')));
            $dicMember = strtoupper(str_replace(' ', '', trim((string) ($r->member_dic ?? ''))));

            $pid  = $hdr->addChild('inv:partnerIdentity', null, $ns_inv);
            $addr = $pid->addChild('typ:address', null, $ns_typ);
            if ($icoMember !== '' || $dicMember !== '') {
                $addr->addChild('typ:company', $this->xmlText($member), $ns_typ);
                if ($icoMember !== '') $addr->addChild('typ:ico', $this->xmlText($icoMember), $ns_typ);
                if ($dicMember !== '') $addr->addChild('typ:dic', $this->xmlText($dicMember), $ns_typ);
            } else {
                $addr->addChild('typ:name', $this->xmlText($member), $ns_typ);
            }

            $city   = $this->buildPartnerCity($r);
            $street = $this->buildPartnerStreet($r);
            $zip    = trim((string) ($r->addr_zip ?? ''));
            $country = trim((string) ($r->addr_country ?? '')) ?: 'Czech Republic';

            if ($city !== '')   $addr->addChild('typ:city',   $this->xmlText($city),   $ns_typ);
            if ($street !== '') $addr->addChild('typ:street', $this->xmlText($street), $ns_typ);
            if ($zip !== '')    $addr->addChild('typ:zip',    $this->xmlText($zip),    $ns_typ);
            $cnode = $addr->addChild('typ:country', null, $ns_typ);
            $cnode->addChild('typ:ids', $this->xmlText($country), $ns_typ);

            $hdr->addChild('inv:text', $this->xmlText('Dobropis (vratka)'), $ns_inv);

            $det = $inv->addChild('inv:invoiceDetail', null, $ns_inv);
            $it  = $det->addChild('inv:invoiceItem', null, $ns_inv);
            $it->addChild('inv:text', $this->xmlText('Vratka přeplatku'), $ns_inv);
            $it->addChild('inv:quantity', '1', $ns_inv);
            $it->addChild('inv:rateVAT',  'high', $ns_inv);
            $hc = $it->addChild('inv:homeCurrency', null, $ns_inv);
            $hc->addChild('typ:unitPrice', number_format($base, 2, '.', ''), $ns_typ);

            $sumNode = $inv->addChild('inv:invoiceSummary', null, $ns_inv);
            $sumNode->addChild('inv:roundingDocument', 'none', $ns_inv);
            $sumNode->addChild('inv:roundingVAT',      'none', $ns_inv);
            $hcs = $sumNode->addChild('inv:homeCurrency', null, $ns_inv);
            $hcs->addChild('typ:priceHigh',    number_format($base, 2, '.', ''), $ns_typ);
            $hcs->addChild('typ:priceHighVAT', number_format($vat,  2, '.', ''), $ns_typ);
            $hcs->addChild('typ:priceHighSum', number_format($sum,  2, '.', ''), $ns_typ);
        }

        return $this->prettyXml((string) $xml->asXML());
    }

    private function xmlText(string $s): string
    {
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s));
    }

    private function dateYmd(string $dt): ?string
    {
        $dt = trim($dt);
        if ($dt !== '' && preg_match('~^\d{4}-\d{2}-\d{2}~', $dt)) {
            return substr($dt, 0, 10);
        }
        return null;
    }

    private function buildPartnerCity(object $r): string
    {
        $town = trim((string) ($r->addr_town ?? ''));
        $q    = trim((string) ($r->addr_quarter ?? ''));
        if ($town === '' && $q === '') return '';
        if ($q !== '' && $town !== '' && mb_stripos($town, $q, 0, 'UTF-8') === false) {
            return $town . ' - ' . $q;
        }
        return $town !== '' ? $town : $q;
    }

    private function buildPartnerStreet(object $r): string
    {
        $street = trim((string) ($r->addr_street ?? ''));
        $no     = trim((string) ($r->addr_street_number ?? ''));
        if ($street === '') return $no;
        if ($no === '') return $street;
        return trim($street . ' ' . $no);
    }

    private function prettyXml(string $xml): string
    {
        $dom = new \DOMDocument('1.0', 'utf-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml);
        return $dom->saveXML();
    }

    /** Format number: trim trailing zeros, dot as decimal separator. */
    private function fmt(float $n): string
    {
        $s = number_format($n, 2, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
