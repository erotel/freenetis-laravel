<?php

namespace App\Services;

use App\Domains\Billing\Actions\ExportPohodaAction;
use App\Domains\Billing\Documents\PohodaRefundsXml;

/**
 * Fasáda → doména Billing (ExportPohodaAction, Documents/Pohoda*Xml).
 * Zůstává kvůli příkazům pohoda:export-monthly a pohoda:reexport-refunds.
 */
class PohodaExportService
{
    public function exportInvoices(int $year, int $month): ?string
    {
        return app(ExportPohodaAction::class)->invoices($year, $month);
    }

    public function exportRefunds(int $year, int $month): ?string
    {
        return app(ExportPohodaAction::class)->refunds($year, $month);
    }

    public function buildRefundXml($items): string
    {
        return app(PohodaRefundsXml::class)->build($items);
    }
}
