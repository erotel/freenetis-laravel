<?php

namespace App\Domains\Billing\Documents;

use App\Models\Setting;

/** XML vratek (vydaný opravný daňový doklad) pro import do Pohody. */
class PohodaRefundsXml
{
    /**
     * Vygeneruje POHODA XML pro vratky (vydaný opravný daňový doklad).
     * Port Kohana modelu Pohoda_Refund_Export_Model::build_xml — DPH 21 %,
     * částka v DB kladná, do XML záporně. Jediný správný formát, který Pohoda přijme.
     */
    public function build($items): string
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
}
