<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Support\ContractEventLog;
use App\Domains\Contracts\Support\ContractStorage;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;

/** Smazání nepodepsaného starého dodatku: PDF, příznaky, OTP kódy, událost. */
class DeleteLegacyAddonAction
{
    public function handle(Contract $contract): bool
    {
        if ($contract->addon_signed) {
            return false;
        }
        $pdf = ContractStorage::path($contract->addon_pdf_path);
        if ($pdf && file_exists($pdf)) {
            @unlink($pdf);
        }

        Contract::where('id', $contract->id)->update(['addon' => 0, 'addon_pdf_path' => null, 'addon_signed_at' => null]);
        DB::connection('contracts')->table('contract_addon_otps')->where('contract_id', $contract->id)->delete();
        ContractEventLog::record($contract->id, 'addon_deleted', ['by' => auth()->user()?->login]);

        return true;
    }
}
