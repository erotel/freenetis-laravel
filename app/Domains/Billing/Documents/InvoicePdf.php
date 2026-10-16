<?php

namespace App\Domains\Billing\Documents;

use App\Models\Invoice;
use App\Models\Member;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;

/**
 * PDF faktury (view invoices.pdf) do storage/app/private/invoices/<rok>/<číslo>.pdf
 * — citlivé (osobní údaje, částky), proto private. Migrace legacy Kohana
 * umístění: `php artisan invoices:migrate-pdf-storage`.
 */
class InvoicePdf
{
    public static function basePath(): string
    {
        return storage_path('app/private/invoices');
    }

    /** @return ?string cesta k PDF, null = selhalo (zalogováno) */
    public function render(Invoice $invoice): ?string
    {
        try {
            $invoice->loadMissing(['items']);

            $year = (int) substr($invoice->date_inv, 0, 4) ?: now()->year;
            $dir  = self::basePath() . '/' . $year;
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $path = $dir . '/' . (int) $invoice->invoice_nr . '.pdf';

            $html = view('invoices.pdf', [
                'invoice'     => $invoice,
                'org'         => Member::with(['addressPoint.street', 'addressPoint.town'])->find(1),
                // invoices nemá bank_account_id — šablona bere účet z invoice.account_nr
                'bankAccount' => null,
                'items'       => $invoice->items,
            ])->render();

            $tmpDir = storage_path('framework/cache/mpdf');
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0775, true);
            }
            $mpdf = new Mpdf([
                'tempDir'           => $tmpDir,
                'mode'              => 'utf-8',
                'format'            => 'A4',
                'margin_top'        => 40,
                'margin_bottom'     => 20,
                'margin_left'       => 10,
                'margin_right'      => 10,
                'default_font'      => 'dejavusans',
                'default_font_size' => 9,
            ]);
            $mpdf->WriteHTML($html);
            $mpdf->Output($path, 'F');

            return $path;
        } catch (\Exception $e) {
            Log::error('InvoiceService: PDF generation failed: ' . $e->getMessage());
            return null;
        }
    }
}
