<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Exceptions\ContractSigningFailed;
use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractParty;
use App\Services\Contracts\PdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Výpověď smlouvy zákazníkem (po ověření): PDF výpovědi s otiskem, smluvní strana termination=ano. */
class GenerateTerminationAction
{
    public function __construct(private PdfService $pdf)
    {
    }

    /** @return string sha256 PDF (hex) @throws ContractSigningFailed */
    public function handle(Contract $contract, Request $request): string
    {
        try {
            $fullpath = $this->pdf->renderTerminationPdf($contract, $request);
            $sha256   = hash('sha256', (string) file_get_contents($fullpath));

            DB::connection('contracts')->beginTransaction();
            ContractEvent::create([
                'contract_id' => $contract->id,
                'event'       => 'termination_generated',
                'meta_json'   => json_encode(['sha256' => $sha256], JSON_UNESCAPED_UNICODE),
            ]);
            ContractParty::where('contract_id', $contract->id)->update(['termination' => 'ano']);
            DB::connection('contracts')->commit();
        } catch (\Throwable $e) {
            if (DB::connection('contracts')->transactionLevel() > 0) {
                DB::connection('contracts')->rollBack();
            }
            throw ContractSigningFailed::from($e);
        }

        return $sha256;
    }
}
