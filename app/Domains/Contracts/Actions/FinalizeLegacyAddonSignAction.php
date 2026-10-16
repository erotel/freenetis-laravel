<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Exceptions\ContractSigningFailed;
use App\Domains\Contracts\Support\PostSignEmails;
use App\Models\Contract;
use App\Models\ContractEvent;
use App\Services\Contracts\PdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Podpis starého dodatku (contracts.addon) po ověření OTP: PDF, příznak, e-mail. */
class FinalizeLegacyAddonSignAction
{
    public function __construct(
        private PdfService $pdf,
        private PostSignEmails $emails,
    ) {
    }

    /** @return string sha256 PDF (hex) @throws ContractSigningFailed */
    public function handle(Contract $contract, Request $request): string
    {
        try {
            $fullpath = $this->pdf->renderAddonPdf($contract, false, $request);
            $sha256   = hash('sha256', (string) file_get_contents($fullpath));

            DB::connection('contracts')->beginTransaction();
            DB::connection('contracts')->table('contracts')->where('id', $contract->id)->update([
                'addon_signed'    => 1,
                'addon_signed_at' => now(),
                'addon_pdf_path'  => $fullpath,
            ]);
            ContractEvent::create([
                'contract_id' => $contract->id,
                'event'       => 'addon_generated',
                'meta_json'   => json_encode(['sha256' => $sha256], JSON_UNESCAPED_UNICODE),
            ]);
            DB::connection('contracts')->commit();

            $contract->addon_signed    = 1;
            $contract->addon_signed_at = now();
            $contract->addon_pdf_path  = $fullpath;
        } catch (\Throwable $e) {
            if (DB::connection('contracts')->transactionLevel() > 0) {
                DB::connection('contracts')->rollBack();
            }
            throw ContractSigningFailed::from($e);
        }

        $this->emails->legacyAddon($contract);

        return $sha256;
    }
}
