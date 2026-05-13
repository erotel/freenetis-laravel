<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;

/**
 * Generuje PDF doklad o vratce při ukončení členství/smlouvy.
 *
 * Záměrně NEvkládá řádek do `invoices` tabulky — vratka je samostatný typ
 * dokumentu (creditNote do Pohody), ne další faktura. Stejný přístup jako
 * legacy Kohana (Refund_Pdf lib + export/refund_pdf view).
 *
 * PDF se ukládá do storage/app/private/refunds/<rok>/refund-<doc_number>.pdf.
 * Cesta se vrací callerovi, který ji uloží do email_queue_attachments + může
 * předat do pohoda_refund_queue, pokud bude potřeba.
 */
class RefundPdfService
{
    public static function basePath(): string
    {
        return storage_path('app/private/refunds');
    }

    public function generate(
        int $memberId,
        string $docNumber,        // "26011" nebo "26ČL0010"
        int $memberOldType,       // 2 (CUSTOMER) nebo 90 (REGULAR) — určuje text položky
        string $leavingDate,      // YYYY-MM-DD
        string $refundAccount,    // "123456789/0800"
        float $refundAmount,      // s DPH (pro zákazníka rozpočítá se 21%)
        string $currency = 'CZK'
    ): ?string {
        $refundAmount = round($refundAmount, 2);
        if ($refundAmount <= 0) {
            Log::warning('RefundPdfService::generate odmítá nulovou nebo zápornou částku', [
                'member_id' => $memberId, 'amount' => $refundAmount,
            ]);
            return null;
        }

        $member = Member::with(['addressPoint.street', 'addressPoint.town'])->find($memberId);
        if (!$member) {
            Log::error('RefundPdfService: member not found', ['member_id' => $memberId]);
            return null;
        }

        // VS — z členova prvního VS (přes accounts.variable_symbols), fallback member_id.
        $varSym = (string) (
            $member->load('accounts.variableSymbols')
                ->accounts
                ->flatMap(fn($a) => $a->variableSymbols)
                ->first()
                ?->variable_symbol
            ?? $memberId
        );

        $ap       = $member->addressPoint;
        $street   = trim(($ap?->street?->street ?? '') . ' ' . ($ap?->street_number ?? ''));
        $town     = trim(($ap?->town?->zip_code ?? '') . ' ' . ($ap?->town?->town ?? ''));
        $address  = trim($street . ($town !== '' ? "\n" . $town : ''));

        try {
            $html = view('refunds.pdf', [
                'doc' => [
                    'doc_number'      => $docNumber,
                    'variable_symbol' => $varSym,
                    'name'            => (string) $member->name,
                    'address'         => $address,
                    'ico'             => (string) ($member->organization_identifier ?? ''),
                    'dic'             => (string) ($member->vat_organization_identifier ?? ''),
                    'member_type'     => $memberOldType,
                    'leaving_date'    => $leavingDate,
                    'amount'          => $refundAmount,
                    'currency'        => $currency,
                    'refund_account'  => $refundAccount,
                ],
            ])->render();

            $tmp = storage_path('framework/cache/mpdf');
            if (!is_dir($tmp)) mkdir($tmp, 0775, true);

            $mpdf = new Mpdf([
                'tempDir'           => $tmp,
                'mode'              => 'utf-8',
                'format'            => 'A4',
                'default_font'      => 'dejavusans',
                'default_font_size' => 10,
                'margin_left'       => 10,
                'margin_right'      => 10,
                'margin_top'        => 12,
                'margin_bottom'     => 12,
            ]);
            $mpdf->WriteHTML($html);

            $year = date('Y');
            $dir  = self::basePath() . '/' . $year;
            if (!is_dir($dir)) mkdir($dir, 0775, true);

            // Sanitize jen znaky nelegální v souborech (Windows/Unix), Unicode písmena
            // a čísla nech být — "Č", "L" jsou validní v FS jménu i v MIME příloze.
            $safeName = preg_replace('/[^\p{L}\p{N}_-]+/u', '-', 'refund-' . $docNumber);
            $path     = $dir . '/' . $safeName . '.pdf';
            $mpdf->Output($path, 'F');

            if (!is_file($path) || !is_readable($path)) {
                throw new \RuntimeException('Refund PDF not created/readable: ' . $path);
            }

            return $path;
        } catch (\Throwable $e) {
            Log::error('RefundPdfService: PDF generation failed: ' . $e->getMessage(), [
                'member_id' => $memberId, 'doc_number' => $docNumber,
            ]);
            return null;
        }
    }
}
