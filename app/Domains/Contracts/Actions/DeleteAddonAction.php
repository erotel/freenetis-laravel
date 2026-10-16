<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Support\AddonType;
use App\Domains\Contracts\Support\ContractEventLog;
use App\Domains\Contracts\Support\ContractStorage;
use App\Models\ContractAddon;

/** Smazání NEpodepsaného dodatku (i s případným PDF náhledu) + událost. */
class DeleteAddonAction
{
    /** @return bool false = podepsaný, nelze smazat */
    public function handle(ContractAddon $addon, AddonType $type): bool
    {
        if ($addon->status === 'signed') {
            return false;
        }
        $path = ContractStorage::path($addon->pdf_path);
        if ($path && file_exists($path)) {
            @unlink($path);
        }
        $contractId = $addon->contract_id;
        $addonId    = $addon->id;
        $addon->delete();

        ContractEventLog::record($contractId, $type->eventPrefix() . '_deleted', ['addon_id' => $addonId, 'by' => auth()->user()?->login]);

        return true;
    }
}
