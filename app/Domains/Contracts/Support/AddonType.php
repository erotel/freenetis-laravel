<?php

namespace App\Domains\Contracts\Support;

use App\Models\ContractAddon;
use App\Services\Contracts\PdfService;
use Illuminate\Http\Request;

/** Typ dodatku ke smlouvě (contract_addons.type) a jeho per-typ detaily. */
enum AddonType: string
{
    case TariffChange      = 'tariff_change';
    case AdditionalService = 'additional_service';
    case ConnectionPoint   = 'connection_point';

    /** Předpona událostí v contract_events („{prefix}_created", „…_signed" …). */
    public function eventPrefix(): string
    {
        return match ($this) {
            self::TariffChange      => 'tariff_addon',
            self::AdditionalService => 'service_addon',
            self::ConnectionPoint   => 'connection_point_addon',
        };
    }

    public function signLinkKind(): SignLinkKind
    {
        return match ($this) {
            self::TariffChange      => SignLinkKind::TariffAddon,
            self::AdditionalService => SignLinkKind::ServiceAddon,
            self::ConnectionPoint   => SignLinkKind::ConnectionPoint,
        };
    }

    /** Vykreslí PDF dodatku; $preview = jen bajty (náhled), jinak uloží a vrátí cestu. */
    public function renderPdf(PdfService $pdf, ContractAddon $addon, bool $preview, ?Request $request): string
    {
        return match ($this) {
            self::TariffChange      => $pdf->renderTariffAddonPdf($addon, $preview, $request),
            self::AdditionalService => $pdf->renderServiceAddonPdf($addon, $preview, $request),
            self::ConnectionPoint   => $pdf->renderConnectionPointAddonPdf($addon, $preview, $request),
        };
    }

    /**
     * E-mail k vydanému zrušení (jen služba a místo): [předmět bez prefixu,
     * „…zrušení <čeho> <název>" do těla, předpona přílohy].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function removalEmail(string $contractNo): array
    {
        return match ($this) {
            self::AdditionalService => ['Zrušení služby ke smlouvě ' . $contractNo, 'služby', 'dodatek-sluzba-zruseni-'],
            self::ConnectionPoint   => ['Zrušení přípojného místa ke smlouvě ' . $contractNo, 'přípojného místa', 'dodatek-misto-zruseni-'],
            self::TariffChange      => throw new \LogicException('Tarifní dodatek se neruší vydáním.'),
        };
    }

    /** Popis pro log při selhání e-mailu zrušení. */
    public function removalLogLabel(): string
    {
        return $this === self::ConnectionPoint ? 'Connection-point removal' : 'Service removal';
    }
}
