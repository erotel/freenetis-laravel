<?php

namespace App\Domains\Contracts\Support;

use App\Domains\Contracts\Pdf\PdfService;
use App\Models\ContractAddon;
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

    // ── veřejné podepisování (/sign/*) ───────────────────────────────────────

    /** View SPA pro podpis dodatku. */
    public function signView(): string
    {
        return match ($this) {
            self::TariffChange      => 'contracts.tariff-addon-sign',
            self::AdditionalService => 'contracts.service-addon-sign',
            self::ConnectionPoint   => 'contracts.connection-point-sign',
        };
    }

    /** Základ názvu souboru PDF v náhledu („…-preview.pdf" / podepsaný „….pdf"). */
    public function previewFileBase(): string
    {
        return match ($this) {
            self::TariffChange      => 'dodatek',
            self::AdditionalService => 'dodatek-sluzba',
            self::ConnectionPoint   => 'dodatek-misto',
        };
    }

    /** download_tokens.file_type podepsaného dodatku. */
    public function downloadFileType(): string
    {
        return match ($this) {
            self::TariffChange      => 'tariff_addon',
            self::AdditionalService => 'service_addon',
            self::ConnectionPoint   => 'connection_point_addon',
        };
    }

    /** Popis v e-mailu po podpisu („Podepsaný dodatek (…) ke smlouvě"). */
    public function signedLabel(): string
    {
        return match ($this) {
            self::TariffChange      => 'změna tarifu',
            self::AdditionalService => 'dodatečná služba',
            self::ConnectionPoint   => 'přípojné místo',
        };
    }

    /** Předpona přílohy e-mailu po podpisu. */
    public function signedAttachmentPrefix(): string
    {
        return match ($this) {
            self::TariffChange      => 'dodatek-tarif-',
            self::AdditionalService => 'dodatek-sluzba-',
            self::ConnectionPoint   => 'dodatek-misto-',
        };
    }

    /** Popis pro log při selhání e-mailu po podpisu. */
    public function signedLogLabel(): string
    {
        return match ($this) {
            self::TariffChange      => 'Tariff addon',
            self::AdditionalService => 'Service addon',
            self::ConnectionPoint   => 'Connection-point addon',
        };
    }

    /** Pole navíc v /sign/…/info podle typu (za společnými). */
    public function infoFields(ContractAddon $addon): array
    {
        return match ($this) {
            self::TariffChange      => [],
            self::AdditionalService => [
                'service_name'   => $addon->service_name,
                'service_price'  => $addon->service_price !== null ? (float) $addon->service_price : null,
                'effective_date' => $addon->effective_date?->format('d.m.Y'),
            ],
            self::ConnectionPoint   => [
                'place_name'     => $addon->service_name,
                'place_speed'    => $addon->place_speed_name,
                'place_price'    => $addon->service_price !== null ? (float) $addon->service_price : null,
                'effective_date' => $addon->effective_date?->format('d.m.Y'),
            ],
        };
    }

    // ── správa smluv (admin) ────────────────────────────────────────────────

    /** Základ názvu PDF ve správě („…-preview.pdf", stažení „…-{id}.pdf"). */
    public function adminFileBase(): string
    {
        return match ($this) {
            self::TariffChange      => 'dodatek-tarif',
            self::AdditionalService => 'dodatek-sluzba',
            self::ConnectionPoint   => 'dodatek-misto',
        };
    }

    /** Klíč session flash s vydaným podpisovým odkazem. */
    public function linkFlashKey(): string
    {
        return match ($this) {
            self::TariffChange      => 'tariff_addon_link',
            self::AdditionalService => 'service_addon_link',
            self::ConnectionPoint   => 'connection_point_addon_link',
        };
    }
}
