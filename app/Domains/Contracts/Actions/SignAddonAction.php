<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Pdf\PdfService;
use App\Domains\Contracts\Support\AddonEffects;
use App\Domains\Contracts\Support\AddonType;
use App\Domains\Contracts\Support\ContractEventLog;
use App\Models\ContractAddon;
use Illuminate\Http\Request;

/**
 * Finalizace dodatku po ověření OTP zákazníka: účinnost = dnes (apply-on-sign,
 * nastaví se PŘED renderem, aby ji PDF neslo), podepsané PDF s otiskem,
 * status signed, událost — a teprve teď dopad na člena (tarif / přiřazení
 * služby / účtování místa). Vydání zrušení bez podpisu: {@see IssueAddonRemovalAction}.
 */
class SignAddonAction
{
    public function __construct(private AddonEffects $effects)
    {
    }

    public function handle(ContractAddon $addon, AddonType $type, PdfService $pdf, ?Request $request = null): ContractAddon
    {
        self::finalize($addon, $type, $pdf, $request, $type->eventPrefix() . '_signed');

        match ($type) {
            AddonType::TariffChange      => $this->effects->applyTariff($addon),
            AddonType::AdditionalService => ($addon->service_action === 'add' && $addon->fee_id) ? $this->effects->assignServiceFee($addon) : null,
            AddonType::ConnectionPoint   => ($addon->service_action === 'add' && $addon->allowed_subnet_id) ? $this->effects->setPlaceCharged($addon, true) : null,
        };

        return $addon->refresh();
    }

    /** Účinnost dnes → PDF → status signed + událost s otiskem sha256. */
    public static function finalize(ContractAddon $addon, AddonType $type, PdfService $pdf, ?Request $request, string $event): void
    {
        $addon->effective_date = now()->toDateString();

        $fullpath = $type->renderPdf($pdf, $addon, false, $request);
        $sha256   = hash('sha256', (string) @file_get_contents($fullpath));

        $addon->update([
            'status'         => 'signed',
            'signed_at'      => now(),
            'pdf_path'       => $fullpath,
            'effective_date' => $addon->effective_date,
        ]);

        ContractEventLog::record($addon->contract_id, $event, ['addon_id' => $addon->id, 'sha256' => $sha256], JSON_UNESCAPED_UNICODE);
    }
}
