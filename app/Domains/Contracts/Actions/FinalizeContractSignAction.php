<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Exceptions\ContractSigningFailed;
use App\Domains\Contracts\Support\PostSignEmails;
use App\Domains\Members\Actions\ActivateMemberAfterContractSignAction;
use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractParty;
use App\Services\Contracts\PdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Podpis smlouvy po ověření OTP: smluvní strana bez výpovědi, finální PDF
 * s otiskem (status signed), aktivace člena ve FreenetIS (čekatel → aktivní)
 * a e-mail s podepsanou smlouvou. Stav ověřuje volající.
 */
class FinalizeContractSignAction
{
    public function __construct(
        private PdfService $pdf,
        private ActivateMemberAfterContractSignAction $activate,
        private PostSignEmails $emails,
    ) {
    }

    /**
     * @return string sha256 PDF (hex)
     * @throws ContractSigningFailed selhání PDF/DB (transakce contractsdb vrácena)
     */
    public function handle(Contract $contract, Request $request): string
    {
        try {
            ContractParty::where('contract_id', $contract->id)->update(['termination' => 'ne']);

            $fullpath = $this->pdf->renderContractPdf($contract, false, $request);
            $raw      = (string) file_get_contents($fullpath);
            $sha256   = hash('sha256', $raw);

            DB::connection('contracts')->beginTransaction();
            DB::connection('contracts')->table('contracts')->where('id', $contract->id)->update([
                'status'     => 'signed',
                'signed_at'  => now(),
                'pdf_path'   => $fullpath,
                'pdf_sha256' => hash('sha256', $raw, true),
            ]);
            ContractEvent::create([
                'contract_id' => $contract->id,
                'event'       => 'pdf_generated',
                'meta_json'   => json_encode(['sha256' => $sha256], JSON_UNESCAPED_UNICODE),
            ]);
            DB::connection('contracts')->commit();

            // query builder update nehydratuje model — e-mail a aktivace čtou z $contract
            $contract->status    = 'signed';
            $contract->signed_at = now();
            $contract->pdf_path  = $fullpath;
        } catch (\Throwable $e) {
            if (DB::connection('contracts')->transactionLevel() > 0) {
                DB::connection('contracts')->rollBack();
            }
            throw ContractSigningFailed::from($e);
        }

        $activation = $this->activate->handle((int) $contract->member_id);
        if ($activation) {
            DB::connection('contracts')->table('contract_events')->insert([
                'contract_id' => $contract->id,
                'event'       => 'member_activated',
                'meta_json'   => json_encode([
                    'member_id'    => (int) $contract->member_id,
                    'old_type'     => $activation['old_type'],
                    'new_type'     => $activation['new_type'],
                    'registration' => 1,
                ], JSON_UNESCAPED_UNICODE),
                'created_at'  => now(),
            ]);
        }

        $this->emails->contract($contract);

        return $sha256;
    }
}
