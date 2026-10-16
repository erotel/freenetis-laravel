<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Documents\PohodaInvoicesXml;
use App\Domains\Billing\Documents\PohodaRefundsXml;
use App\Domains\Billing\Repositories\PohodaExportRepository;

/**
 * Měsíční export do Pohody: XML faktur / vratek do storage/app/private/
 * pohoda-exports a překlopení vyexportovaných dokladů. Prázdný výběr → null.
 */
class ExportPohodaAction
{
    public function __construct(
        private PohodaExportRepository $repo,
        private PohodaInvoicesXml $invoicesXml,
        private PohodaRefundsXml $refundsXml,
    ) {
    }

    public static function dir(): string
    {
        return storage_path('app/private/pohoda-exports/');
    }

    /** @return ?string cesta k souboru pohoda_YYYY_MM.xml */
    public function invoices(int $year, int $month): ?string
    {
        $invoices = $this->repo->invoicesUpTo(self::monthEnd($year, $month));
        if ($invoices->isEmpty()) {
            return null;
        }

        $xml  = $this->invoicesXml->build($invoices, fn (int $id) => $this->repo->invoiceItems($id));
        $file = $this->write(sprintf('pohoda_%04d_%02d.xml', $year, $month), $xml);
        $this->repo->markInvoicesExported($invoices->pluck('id')->all());

        return $file;
    }

    /** @return ?string cesta k souboru pohoda_refunds_YYYY_MM_His.xml */
    public function refunds(int $year, int $month): ?string
    {
        $items = $this->repo->refundsUpTo(self::monthEnd($year, $month) . ' 23:59:59');
        if ($items->isEmpty()) {
            return null;
        }

        $xml  = $this->refundsXml->build($items);
        $file = $this->write(sprintf('pohoda_refunds_%04d_%02d_%s.xml', $year, $month, date('His')), $xml);
        $this->repo->markRefundsExported($items->pluck('id')->all());

        return $file;
    }

    private static function monthEnd(int $year, int $month): string
    {
        return sprintf('%04d-%02d-%02d', $year, $month, (int) date('t', mktime(0, 0, 0, $month, 1, $year)));
    }

    private function write(string $name, string $content): string
    {
        $dir = self::dir();
        is_dir($dir) || mkdir($dir, 0755, true);
        file_put_contents($dir . $name, $content);
        return $dir . $name;
    }
}
