<?php

namespace App\Services;

use App\Domains\Contracts\Actions\CancelContractAction;
use App\Domains\Contracts\Actions\CreateConnectionPointAddonAction;
use App\Domains\Contracts\Actions\CreateContractAction;
use App\Domains\Contracts\Actions\CreateLegacyAddonAction;
use App\Domains\Contracts\Actions\CreateServiceAddonAction;
use App\Domains\Contracts\Actions\CreateTariffAddonAction;
use App\Domains\Contracts\Actions\DeleteAddonAction;
use App\Domains\Contracts\Actions\DeleteLegacyAddonAction;
use App\Domains\Contracts\Actions\IssueAddonRemovalAction;
use App\Domains\Contracts\Actions\IssueSignLinkAction;
use App\Domains\Contracts\Actions\RefreshContractPartyAction;
use App\Domains\Contracts\Actions\SignAddonAction;
use App\Domains\Contracts\Actions\TerminateContractAction;
use App\Domains\Contracts\Actions\UpdateServiceAddressAction;
use App\Domains\Contracts\Queries\ContractListQuery;
use App\Domains\Contracts\Repositories\ContractAddonRepository;
use App\Domains\Contracts\Repositories\ContractRepository;
use App\Domains\Contracts\Support\AddonType;
use App\Domains\Contracts\Support\ContractStorage;
use App\Domains\Contracts\Support\TariffSnapshot;
use App\Domains\Contracts\Support\SignLinkKind;
use App\Domains\Contracts\Support\SignLinkToken;
use App\Models\Contract;
use App\Models\ContractAddon;
use App\Models\Member;
use App\Services\Contracts\PdfService;
use Illuminate\Http\Request;

/**
 * Fasáda nad doménou App\Domains\Contracts (smlouvy, dodatky, podpisové
 * odkazy). Zůstává kvůli stávajícím volajícím (MemberFee/AllowedSubnet
 * controllery, karta člena, menu, view smlouvy); nový kód používá doménu přímo.
 */
class ContractService
{
    public function __construct()
    {
        // Bezpečnostní guard tajemství podpisových tokenů (vyhodí výjimku při slabém secretu).
        app(SignLinkToken::class);
    }

    public function getByMemberId(int $memberId): ?Contract
    {
        return app(ContractRepository::class)->forMember($memberId);
    }

    /** Návrh smlouvy se snímkem smluvní strany — viz CreateContractAction. */
    public function createContract(Member $member): Contract
    {
        return app(CreateContractAction::class)->handle($member);
    }

    /** Přepsání snímku strany živými daty (jen návrh) — viz RefreshContractPartyAction. */
    public function refreshPartyFromMember(Contract $contract): bool
    {
        return app(RefreshContractPartyAction::class)->handle($contract);
    }

    /** Zrušení (smazání) nepodepsané smlouvy — viz CancelContractAction. */
    public function cancelContract(Contract $contract, ?string $reason = null): bool
    {
        return app(CancelContractAction::class)->handle($contract, $reason);
    }

    /** Ukončení podepsané smlouvy — viz TerminateContractAction. */
    public function terminateContract(Contract $contract, ?string $reason = null, ?string $terminationDate = null): bool
    {
        return app(TerminateContractAction::class)->handle($contract, $reason, $terminationDate);
    }

    /**
     * Snapshot z živých dat člena → pole pro contract_parties (bez `contract_id`).
     * Používá createContract() i refreshPartyFromMember().
     */
    public function tariffSnapshot(Member $member): array
    {
        return app(TariffSnapshot::class)->current($member);
    }

    /** Snímek pro navrhovanou třídu rychlosti (new_* tarifního dodatku). */
    public function tariffSnapshotForSpeedClass(Member $member, int $speedClassId): array
    {
        return app(TariffSnapshot::class)->forSpeedClass($member, $speedClassId);
    }

    /** Návrh dodatku „změna tarifu" — viz CreateTariffAddonAction. */
    public function createTariffChangeAddon(int $contractId, int $newSpeedClassId): ?ContractAddon
    {
        return app(CreateTariffAddonAction::class)->handle($contractId, $newSpeedClassId);
    }

    public function deleteTariffAddon(ContractAddon $addon): bool
    {
        return app(DeleteAddonAction::class)->handle($addon, AddonType::TariffChange);
    }

    public function tariffAddonPdfPath(ContractAddon $addon): ?string
    {
        return ContractStorage::path($addon->pdf_path);
    }

    /** Všechny dodatky „změna tarifu" dané smlouvy (nejnovější první). */
    public function tariffAddons(int $contractId)
    {
        return app(ContractAddonRepository::class)->forContract($contractId, AddonType::TariffChange);
    }

    public function getTariffAddon(int $id): ?ContractAddon
    {
        return app(ContractAddonRepository::class)->find($id, AddonType::TariffChange);
    }

    /** Podpisový odkaz dodatku tarifu (token nese cid i aid) + e-mail zákazníkovi. */
    public function issueTariffAddonLink(int $addonId): array
    {
        $addon = ContractAddon::find($addonId);
        if (!$addon) {
            return ['url' => null, 'email_sent' => false, 'email' => null];
        }
        return app(IssueSignLinkAction::class)->handle((int) $addon->contract_id, SignLinkKind::TariffAddon, $addon);
    }

    /** Ověří podpisový token dodatku (sig + exp). Vrací addon_id, nebo null. */
    public function verifyTariffAddonToken(string $token): ?int
    {
        return app(SignLinkToken::class)->addonId($token);
    }

    /** Finalizace po OTP + aplikace tarifu na člena — viz SignAddonAction. */
    public function signTariffAddon(ContractAddon $addon, PdfService $pdf, ?Request $request = null): ContractAddon
    {
        return app(SignAddonAction::class)->handle($addon, AddonType::TariffChange, $pdf, $request);
    }

    // ── Dodatek: dodatečná služba (contract_addons, type='additional_service') ──

    /** Návrh dodatku „dodatečná služba" — viz CreateServiceAddonAction. */
    public function createServiceAddon(int $contractId, int $feeId, string $action = 'add'): ?ContractAddon
    {
        return app(CreateServiceAddonAction::class)->handle($contractId, $feeId, $action);
    }

    public function deleteServiceAddon(ContractAddon $addon): bool
    {
        return app(DeleteAddonAction::class)->handle($addon, AddonType::AdditionalService);
    }

    public function serviceAddonPdfPath(ContractAddon $addon): ?string
    {
        return ContractStorage::path($addon->pdf_path);
    }

    /** Všechny dodatky „dodatečná služba" dané smlouvy (nejnovější první). */
    public function serviceAddons(int $contractId)
    {
        return app(ContractAddonRepository::class)->forContract($contractId, AddonType::AdditionalService);
    }

    public function getServiceAddon(int $id): ?ContractAddon
    {
        return app(ContractAddonRepository::class)->find($id, AddonType::AdditionalService);
    }

    /** Podpisový odkaz dodatku služby (jen přidání; odebrání se nepodepisuje). */
    public function issueServiceAddonLink(int $addonId): array
    {
        $addon = ContractAddon::where('type', 'additional_service')->find($addonId);
        if (!$addon || $addon->service_action !== 'add') {
            return ['url' => null, 'email_sent' => false, 'email' => null];
        }
        return app(IssueSignLinkAction::class)->handle((int) $addon->contract_id, SignLinkKind::ServiceAddon, $addon);
    }

    /** Ověří podpisový token dodatku služby (sig + exp). Vrací addon_id, nebo null. */
    public function verifyServiceAddonToken(string $token): ?int
    {
        return app(SignLinkToken::class)->addonId($token);
    }

    /** Finalizace po OTP + přiřazení služby členovi — viz SignAddonAction. */
    public function signServiceAddon(ContractAddon $addon, PdfService $pdf, ?Request $request = null): ContractAddon
    {
        return app(SignAddonAction::class)->handle($addon, AddonType::AdditionalService, $pdf, $request);
    }

    /** Vydání zrušení služby bez podpisu zákazníka — viz IssueAddonRemovalAction. */
    public function issueServiceRemoval(ContractAddon $addon, PdfService $pdf): ?ContractAddon
    {
        return app(IssueAddonRemovalAction::class)->handle($addon, AddonType::AdditionalService, $pdf);
    }

    // ── Dodatek: přípojné místo (contract_addons, type='connection_point') ──

    /** Návrh dodatku „přípojné místo" — viz CreateConnectionPointAddonAction. */
    public function createConnectionPointAddon(int $contractId, int $allowedSubnetId, string $action = 'add', string $address = ''): ?ContractAddon
    {
        return app(CreateConnectionPointAddonAction::class)->handle($contractId, $allowedSubnetId, $action, $address);
    }

    public function deleteConnectionPointAddon(ContractAddon $addon): bool
    {
        return app(DeleteAddonAction::class)->handle($addon, AddonType::ConnectionPoint);
    }

    /** Všechny dodatky „přípojné místo" dané smlouvy (nejnovější první). */
    public function connectionPointAddons(int $contractId)
    {
        return app(ContractAddonRepository::class)->forContract($contractId, AddonType::ConnectionPoint);
    }

    public function getConnectionPointAddon(int $id): ?ContractAddon
    {
        return app(ContractAddonRepository::class)->find($id, AddonType::ConnectionPoint);
    }

    /** Podpisový odkaz dodatku přípojného místa (jen přidání). */
    public function issueConnectionPointAddonLink(int $addonId): array
    {
        $addon = ContractAddon::where('type', 'connection_point')->find($addonId);
        if (!$addon || $addon->service_action !== 'add') {
            return ['url' => null, 'email_sent' => false, 'email' => null];
        }
        return app(IssueSignLinkAction::class)->handle((int) $addon->contract_id, SignLinkKind::ConnectionPoint, $addon);
    }

    /** Ověří podpisový token dodatku přípojného místa. Vrací addon_id, nebo null. */
    public function verifyConnectionPointAddonToken(string $token): ?int
    {
        return app(SignLinkToken::class)->addonId($token);
    }

    /** Finalizace po OTP + zapnutí účtování místa — viz SignAddonAction. */
    public function signConnectionPointAddon(ContractAddon $addon, PdfService $pdf, ?Request $request = null): ContractAddon
    {
        return app(SignAddonAction::class)->handle($addon, AddonType::ConnectionPoint, $pdf, $request);
    }

    /** Vydání zrušení přípojného místa bez podpisu zákazníka — viz IssueAddonRemovalAction. */
    public function issueConnectionPointRemoval(ContractAddon $addon, PdfService $pdf): ?ContractAddon
    {
        return app(IssueAddonRemovalAction::class)->handle($addon, AddonType::ConnectionPoint, $pdf);
    }

    /** Ruční místo připojení u návrhu — viz UpdateServiceAddressAction. */
    public function updateServiceAddress(Contract $contract, string $address): bool
    {
        return app(UpdateServiceAddressAction::class)->handle($contract, $address);
    }

    public function issueAccessLink(int $contractId): array
    {
        return app(IssueSignLinkAction::class)->handle($contractId, SignLinkKind::Contract);
    }

    public function createAddon(int $contractId): bool
    {
        return app(CreateLegacyAddonAction::class)->handle($contractId);
    }

    public function sendAddonLink(int $contractId): array
    {
        return app(IssueSignLinkAction::class)->handle($contractId, SignLinkKind::LegacyAddon);
    }

    public function deleteAddon(Contract $contract): bool
    {
        return app(DeleteLegacyAddonAction::class)->handle($contract);
    }

    public function getAddonStatus(Contract $contract): string
    {
        return CreateLegacyAddonAction::status($contract);
    }

    public function addonPdfPath(Contract $contract): ?string
    {
        return ContractStorage::path($contract->addon_pdf_path);
    }

    public function pdfPath(Contract $contract): ?string
    {
        return ContractStorage::path($contract->pdf_path);
    }

    /** Odznak v menu: nepodepsané smlouvy aktivních členů. */
    public function countUnsigned(): int
    {
        return app(ContractListQuery::class)->countUnsigned();
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private function generateContractNo(int $memberId): string
    {
        return app(ContractRepository::class)->nextContractNo($memberId);
    }

    /**
     * Ověří podpisový token (smlouva i dodatek nesou cid). Vrací id smlouvy,
     * nebo null (neplatný/expirovaný/podvržený).
     */
    public function verifyAccessToken(string $token): ?int
    {
        return app(SignLinkToken::class)->contractId($token);
    }
}
