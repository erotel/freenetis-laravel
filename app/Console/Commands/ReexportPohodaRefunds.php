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
                                {--month= : YYYY-MM, filtr na created_at měsíc}
                                {--from= : YYYY-MM-DD, dolní hranice created_at (>=)}
                                {--to= : YYYY-MM-DD, horní hranice created_at (<=)}
                                {--type= : member_type — 2=zákazník, 90=řádný člen; default všechny}
                                {--no-pdf : nepřegenerovávat PDF, jen XML}
                                {--xml-only : alias pro --no-pdf}
                                {--pdf-only : jen přegenerovat PDF, žádné XML}';

    protected $description = 'Re-export PDF dobropisů a Pohoda XML z pohoda_refund_queue dle filtru (nemění status)';

    public function handle(): int
    {
        $dateFrom = null;
        $dateTo   = null;

        if ($monthOpt = (string) $this->option('month')) {
            if (!preg_match('/^(\d{4})-(\d{2})$/', $monthOpt, $m)) {
                $this->error('--month musí být YYYY-MM (např. 2026-04).');
                return 1;
            }
            $dateFrom = sprintf('%04d-%02d-01 00:00:00', (int) $m[1], (int) $m[2]);
            $dateTo   = date('Y-m-t 23:59:59', strtotime($dateFrom));
        }
        if ($fromOpt = (string) $this->option('from')) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromOpt)) {
                $this->error('--from musí být YYYY-MM-DD.');
                return 1;
            }
            $dateFrom = $fromOpt . ' 00:00:00';
        }
        if ($toOpt = (string) $this->option('to')) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toOpt)) {
                $this->error('--to musí být YYYY-MM-DD.');
                return 1;
            }
            $dateTo = $toOpt . ' 23:59:59';
        }

        $typeOpt    = $this->option('type');
        $memberType = ($typeOpt === null || $typeOpt === '') ? null : (int) $typeOpt;
        $skipPdf    = (bool) ($this->option('no-pdf') || $this->option('xml-only'));
        $skipXml    = (bool) $this->option('pdf-only');

        if ($skipPdf && $skipXml) {
            $this->error('--pdf-only a --no-pdf/--xml-only se navzájem vylučují.');
            return 1;
        }
        if ($dateFrom === null && $dateTo === null && $memberType === null) {
            $this->error('Zadej aspoň jeden filtr: --month, --from, --to nebo --type. Bez nich by se přegenerovalo všechno.');
            return 1;
        }

        $q = DB::table('pohoda_refund_queue as q')
            ->join('members as m', 'm.id', '=', 'q.member_id')
            ->leftJoin('address_points as ap', 'ap.id', '=', 'm.address_point_id')
            ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
            ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
            ->leftJoin('countries as c', 'c.id', '=', 'ap.country_id')
            ->orderBy('q.id')
            ->select(
                'q.*',
                'm.name as member_name',
                'm.leaving_date',
                'm.organization_identifier as member_ico',
                'm.vat_organization_identifier as member_dic',
                's.street as addr_street',
                'ap.street_number as addr_street_number',
                't.town as addr_town',
                't.quarter as addr_quarter',
                't.zip_code as addr_zip',
                'c.country_name as addr_country'
            );

        if ($dateFrom !== null) $q->where('q.created_at', '>=', $dateFrom);
        if ($dateTo   !== null) $q->where('q.created_at', '<=', $dateTo);
        if ($memberType !== null) $q->where('q.member_type', $memberType);

        $items = $q->get();
        if ($items->isEmpty()) {
            $this->warn('V queue není žádný řádek pro daný filtr.');
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

        if ($skipXml) {
            $this->info('Hotovo (--pdf-only). XML přeskočeno. Status řádků v queue se NEZMĚNIL.');
            return 0;
        }

        // 2. XML — používáme stejnou metodu jako monthly export (Pohoda formát:
        // issuedCorrectiveTax, DPH 21 %, záporné částky). Status řádků se nemění.
        $xmlString = app(\App\Services\PohodaExportService::class)->buildRefundXml($items);

        $typeTag = $memberType === MemberType::CUSTOMER ? '_customer'
                 : ($memberType === MemberType::REGULAR ? '_regular'
                 : ($memberType !== null ? '_type' . $memberType : ''));
        $rangeTag = '';
        if ($dateFrom !== null) $rangeTag .= '_from' . substr($dateFrom, 0, 10);
        if ($dateTo   !== null) $rangeTag .= '_to'   . substr($dateTo,   0, 10);

        $exportDir = storage_path('app/private/pohoda-exports/');
        is_dir($exportDir) || mkdir($exportDir, 0755, true);
        $filename = $exportDir . sprintf('pohoda_refunds_reexport%s%s_%s.xml',
            $rangeTag, $typeTag, date('His'));
        file_put_contents($filename, $xmlString);

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
