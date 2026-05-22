<?php

namespace App\Console\Commands;

use App\Helpers\MemberType;
use App\Models\Setting;
use App\Services\RefundPdfService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Znovuvyrobí PDF dobropisů + Pohoda XML pro filtrovanou podmnožinu řádků
 * z pohoda_refund_queue. Použití po ručním přečíslování duplicit, kdy už
 * jsou řádky ve stavu 'exported' a normální monthly export je nevezme.
 *
 * Tahle úloha NEUPRAVUJE status ani exported_at — jen vyrobí soubory, které
 * admin pošle účetní/do Pohody ručně.
 */
class ReexportPohodaRefunds extends Command
{
    protected $signature = 'pohoda:reexport-refunds
                                {--month= : YYYY-MM, filtr na created_at (povinné)}
                                {--type= : member_type — 2=zákazník, 90=řádný člen; default všechny}
                                {--no-pdf : nepřegenerovávat PDF, jen XML}
                                {--xml-only : alias pro --no-pdf}';

    protected $description = 'Re-export PDF dobropisů a Pohoda XML pro vybraný měsíc + typ z pohoda_refund_queue (nemění status)';

    public function handle(): int
    {
        $monthOpt = (string) $this->option('month');
        if (!preg_match('/^(\d{4})-(\d{2})$/', $monthOpt, $m)) {
            $this->error('Použij --month=YYYY-MM (např. --month=2026-04).');
            return 1;
        }
        $year     = (int) $m[1];
        $monthNum = (int) $m[2];
        $dateFrom = sprintf('%04d-%02d-01 00:00:00', $year, $monthNum);
        $dateTo   = date('Y-m-t 23:59:59', strtotime($dateFrom));

        $typeOpt    = $this->option('type');
        $memberType = ($typeOpt === null || $typeOpt === '') ? null : (int) $typeOpt;
        $skipPdf    = (bool) ($this->option('no-pdf') || $this->option('xml-only'));

        $q = DB::table('pohoda_refund_queue as q')
            ->join('members as m', 'm.id', '=', 'q.member_id')
            ->leftJoin('address_points as ap', 'ap.id', '=', 'm.address_point_id')
            ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
            ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
            ->whereBetween('q.created_at', [$dateFrom, $dateTo])
            ->orderBy('q.id')
            ->select(
                'q.*',
                'm.name as member_name',
                'm.leaving_date',
                'm.organization_identifier',
                'm.vat_organization_identifier',
                's.street',
                'ap.street_number',
                't.town',
                't.zip_code'
            );

        if ($memberType !== null) {
            $q->where('q.member_type', $memberType);
        }

        $items = $q->get();
        if ($items->isEmpty()) {
            $this->warn("V queue není žádný řádek pro {$monthOpt}"
                . ($memberType !== null ? " a type={$memberType}" : '') . '.');
            return 0;
        }

        $this->info('Vybráno řádků: ' . $items->count());

        // 1. PDF
        if (!$skipPdf) {
            $pdfSvc = new RefundPdfService();
            $okPdf  = 0;
            foreach ($items as $it) {
                $path = $pdfSvc->generate(
                    (int) $it->member_id,
                    (string) $it->doc_number,
                    (int) $it->member_type,
                    (string) ($it->leaving_date ?? date('Y-m-d', strtotime((string) $it->created_at))),
                    (string) $it->refund_account,
                    (float) $it->amount,
                    (string) ($it->currency ?? 'CZK')
                );
                if ($path) {
                    $this->line("  PDF: {$it->doc_number} -> " . basename($path));
                    $okPdf++;
                } else {
                    $this->warn("  PDF FAIL: doc={$it->doc_number} member_id={$it->member_id}");
                }
            }
            $this->info("PDF přegenerováno: {$okPdf}/" . $items->count());
        }

        // 2. XML — kopie logiky z PohodaExportService::exportRefunds, ale
        // bez zápisu do status/exported_at a s vlastním názvem souboru.
        $ico         = (string) Setting::get('organization_identifier', '');
        $application = (string) Setting::get('pohoda_application', 'Freenetis');
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
        $xml->writeAttribute('note', 'Reexport from Freenetis');
        $xml->writeAttributeNs('xmlns', 'inv', null, 'http://www.stormware.cz/schema/version_2/invoice.xsd');
        $xml->writeAttributeNs('xmlns', 'typ', null, 'http://www.stormware.cz/schema/version_2/type.xsd');

        foreach ($items as $item) {
            $xml->startElementNs('dat', 'dataPackItem', null);
            $xml->writeAttribute('id', 'DP' . $item->doc_number);
            $xml->writeAttribute('version', '2.0');

            $xml->startElementNs('inv', 'invoice', null);
            $xml->writeAttribute('version', '2.0');

            $xml->startElementNs('inv', 'invoiceHeader', null);
            $xml->writeElementNs('inv', 'invoiceType', null, 'creditNote');

            $xml->startElementNs('inv', 'number', null);
            $xml->writeElementNs('typ', 'numberRequested', null, (string) $item->doc_number);
            $xml->endElement();

            $xml->writeElementNs('inv', 'date', null, date('Y-m-d', strtotime((string) $item->created_at)));
            $xml->writeElementNs('inv', 'note', null, (string) ($item->note ?? $item->reason ?? ''));

            $xml->startElementNs('inv', 'partnerIdentity', null);
            $xml->startElementNs('typ', 'address', null);
            $icoPartner = trim((string) ($item->organization_identifier ?? ''));
            if ($icoPartner !== '') {
                $xml->writeElementNs('typ', 'company', null, (string) ($item->member_name ?? ''));
                $xml->writeElementNs('typ', 'name',    null, '');
                $xml->writeElementNs('typ', 'ico',     null, $icoPartner);
            } else {
                $xml->writeElementNs('typ', 'company', null, '');
                $xml->writeElementNs('typ', 'name',    null, mb_substr((string) ($item->member_name ?? ''), 0, 32, 'UTF-8'));
            }
            $street = trim(((string) ($item->street ?? '')) . ' ' . ((string) ($item->street_number ?? '')));
            $xml->writeElementNs('typ', 'street', null, $street);
            $xml->writeElementNs('typ', 'city',   null, (string) ($item->town     ?? ''));
            $xml->writeElementNs('typ', 'zip',    null, (string) ($item->zip_code ?? ''));
            $xml->startElementNs('typ', 'country', null);
            $xml->writeElementNs('typ', 'ids', null, 'Czech Republic');
            $xml->endElement(); // typ:country
            $xml->endElement(); // typ:address
            $xml->endElement(); // inv:partnerIdentity
            $xml->endElement(); // inv:invoiceHeader

            $amount = (float) ($item->amount ?? 0);
            $xml->startElementNs('inv', 'invoiceDetail', null);
            $xml->startElementNs('inv', 'invoiceItem', null);
            $xml->writeElementNs('inv', 'text',     null, (string) ($item->reason ?? 'Vrácení'));
            $xml->writeElementNs('inv', 'quantity', null, '1');
            $xml->writeElementNs('inv', 'rateVAT',  null, 'none');
            $xml->startElementNs('inv', 'homeCurrency', null);
            $xml->writeElementNs('typ', 'unitPrice', null, $this->fmt($amount));
            $xml->writeElementNs('typ', 'price',     null, $this->fmt($amount));
            $xml->writeElementNs('typ', 'priceVAT',  null, '0');
            $xml->writeElementNs('typ', 'priceSum',  null, (string) round($amount));
            $xml->endElement(); // homeCurrency
            $xml->endElement(); // invoiceItem
            $xml->endElement(); // invoiceDetail

            $xml->endElement(); // inv:invoice
            $xml->endElement(); // dat:dataPackItem
        }

        $xml->endElement(); // dat:dataPack
        $xml->endDocument();

        $typeTag  = $memberType === MemberType::CUSTOMER ? '_customer'
                  : ($memberType === MemberType::REGULAR ? '_regular'
                  : ($memberType !== null ? '_type' . $memberType : ''));
        $exportDir = '/var/www/html/freenetis/data/export/';
        is_dir($exportDir) || mkdir($exportDir, 0755, true);
        $filename = $exportDir . sprintf('pohoda_refunds_reexport_%04d_%02d%s_%s.xml',
            $year, $monthNum, $typeTag, date('His'));
        file_put_contents($filename, $xml->outputMemory());

        $this->info('XML: ' . $filename);
        $this->info('Hotovo. Status řádků v queue se NEZMĚNIL.');
        return 0;
    }

    private function fmt(float $n): string
    {
        $s = number_format($n, 2, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
