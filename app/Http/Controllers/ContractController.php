<?php

namespace App\Http\Controllers;

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
use App\Domains\Contracts\Actions\TerminateContractAction;
use App\Domains\Contracts\Actions\UpdateServiceAddressAction;
use App\Domains\Contracts\Queries\ContractCardQuery;
use App\Domains\Contracts\Queries\ContractListQuery;
use App\Domains\Contracts\Repositories\ContractRepository;
use App\Domains\Contracts\Support\AddonType;
use App\Domains\Contracts\Support\ContractPreconditions;
use App\Domains\Contracts\Support\ContractStorage;
use App\Domains\Contracts\Support\SignLinkKind;
use App\Models\Contract;
use App\Models\ContractAddon;
use App\Models\Member;
use App\Models\User;
use App\Services\Contracts\PdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Správa smluv a dodatků (karta smlouvy člena, seznam). Logika v app/Domains/Contracts. */
class ContractController extends Controller
{
    private const ACL_SECTION = 'Members_Controller';
    private const ACL_VALUE   = 'members';

    private const LINK_SENT    = 'Odkaz pro podpis dodatku byl vygenerován a odeslán na email zákazníka.';
    private const LINK_NOEMAIL = 'Odkaz pro podpis dodatku byl vygenerován. Zákazník nemá email — zkopírujte odkaz ručně.';

    public function __construct(private ContractRepository $contracts) {}

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    private function back(int $memberId, string $type, string $message): RedirectResponse
    {
        return redirect()->route('contracts.show', $memberId)->with($type, $message);
    }

    public function show(int $memberId, ContractCardQuery $card): View
    {
        // Admin (edit_all/view_all) vidí vše; zákazník jen vlastní profil a bez
        // administrativní historie dodatků.
        $isOwn   = (auth()->user()?->member_id == $memberId);
        $canEdit = $this->can('edit_all');
        $isAdmin = $canEdit || $this->can('view_all');
        abort_unless($isAdmin || $isOwn, 403);

        $member = Member::findOrFail($memberId);

        return view('contracts.show', ['member' => $member, 'canEdit' => $canEdit, 'isAdmin' => $isAdmin,
            // Výběr cílového tarifu do dodatku jen s jemným právem qos_ceil.
            'canEditQos' => $this->aclCheck('edit_all', 'Members_Controller', 'qos_ceil'),
        ] + $card->data($memberId));
    }

    public function create(int $memberId, CreateContractAction $create): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $member = Member::with(['users.contacts.enumType', 'accounts.variableSymbols', 'addressPoint.street', 'addressPoint.town', 'speedClass'])
            ->findOrFail($memberId);
        $existing = $this->contracts->forMember($memberId);

        switch (ContractPreconditions::whyNotCreatable($member, $existing)) {
            case ContractPreconditions::REGULAR_MEMBER:
                return redirect()->route('members.show', $memberId)
                    ->with('error', 'Řádný člen (typ 90) nemá smlouvu — vytvoření není povoleno.');
            case ContractPreconditions::EXISTS:
                return $this->back($memberId, 'error', 'Smlouva pro tohoto člena již existuje (#' . $existing->contract_no . ').');
            case ContractPreconditions::NO_SPEED:
                return redirect()->route('members.edit', $memberId)
                    ->with('error', 'Před vytvořením smlouvy nastavte třídu rychlosti (QoS) v editaci člena.');
            case ContractPreconditions::NO_BIRTHDAY:
                $mainUser = $member->users->firstWhere('type', User::MAIN_USER);
                return ($mainUser ? redirect()->route('users.edit', $mainUser->id) : redirect()->route('members.edit', $memberId))
                    ->with('error', 'Před vytvořením smlouvy vyplňte datum narození hlavního uživatele (nebo IČO u organizace).');
        }

        $contract = $create->handle($member);

        return $this->back($memberId, 'success', 'Smlouva ' . $contract->contract_no . ' byla vytvořena.');
    }

    public function refresh(int $memberId, RefreshContractPartyAction $refresh): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->forMember($memberId);
        if (!$contract) {
            return redirect()->route('members.show', $memberId)->with('error', 'Smlouva nenalezena.');
        }
        if ($contract->status !== 'draft') {
            return $this->back($memberId, 'error', 'Aktualizace údajů je možná jen u smlouvy ve stavu Návrh. '
                . 'V ostatních stavech smlouvu nejdřív zrušte a vytvořte novou.');
        }

        return $refresh->handle($contract)
            ? $this->back($memberId, 'success', 'Údaje ve smlouvě byly aktualizovány podle aktuálních údajů člena.')
            : $this->back($memberId, 'error', 'Údaje se nepodařilo aktualizovat.');
    }

    public function updateServiceAddress(int $memberId, Request $request, UpdateServiceAddressAction $update): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->forMember($memberId);
        if (!$contract) {
            return redirect()->route('members.show', $memberId)->with('error', 'Smlouva nenalezena.');
        }
        if ($contract->status !== 'draft') {
            return $this->back($memberId, 'error', 'Místo připojení lze upravit jen u smlouvy ve stavu Návrh.');
        }

        $validated = $request->validate(['service_full_address' => 'nullable|string|max:255']);

        return $update->handle($contract, (string) ($validated['service_full_address'] ?? ''))
            ? $this->back($memberId, 'success', 'Místo připojení bylo upraveno.')
            : $this->back($memberId, 'error', 'Místo připojení se nepodařilo upravit.');
    }

    public function cancel(int $memberId, Request $request, CancelContractAction $cancel): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->forMember($memberId);
        if (!$contract) {
            return redirect()->route('members.show', $memberId)->with('error', 'Smlouva nenalezena.');
        }
        if (!in_array($contract->status, ['draft', 'otp_sent', 'otp_verified'], true)) {
            return $this->back($memberId, 'error', 'Zrušit lze pouze nepodepsanou smlouvu.');
        }

        return $cancel->handle($contract, (string) $request->input('reason', '') ?: null)
            ? $this->back($memberId, 'success', 'Smlouva byla zrušena. Můžete vytvořit novou.')
            : $this->back($memberId, 'error', 'Smlouvu se nepodařilo zrušit.');
    }

    public function terminate(int $memberId, Request $request, TerminateContractAction $terminate): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->forMember($memberId);
        if (!$contract) {
            return redirect()->route('members.show', $memberId)->with('error', 'Smlouva nenalezena.');
        }
        if ($contract->status !== 'signed') {
            return $this->back($memberId, 'error', 'Ukončit lze pouze podepsanou smlouvu.');
        }

        $ok = $terminate->handle($contract,
            (string) $request->input('reason', '') ?: null,
            (string) $request->input('termination_date', '') ?: null);

        return $ok
            ? $this->back($memberId, 'success', 'Smlouva byla označena jako ukončená.')
            : $this->back($memberId, 'error', 'Smlouvu se nepodařilo ukončit.');
    }

    public function sendLink(int $memberId, IssueSignLinkAction $issue): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->forMember($memberId);
        if (!$contract) {
            return redirect()->route('members.show', $memberId)->with('error', 'Smlouva nenalezena.');
        }
        if (!in_array($contract->status, ['draft', 'otp_sent', 'otp_verified'])) {
            return $this->back($memberId, 'error', 'Odkaz lze odeslat pouze pro smlouvy ve stavu Návrh nebo Čeká na podpis.');
        }

        $result = $issue->handle($contract->id, SignLinkKind::Contract);

        return $this->back($memberId, 'success', $result['email_sent']
                ? 'Podpisový odkaz byl vygenerován a odeslán na email zákazníka.'
                : 'Podpisový odkaz byl vygenerován. Zákazník nemá email — zkopírujte odkaz ručně.')
            ->with('sign_link', $result['url']);
    }

    // ── Starý dodatek (příznak contracts.addon) ─────────────────────────────

    public function createAddon(int $memberId, CreateLegacyAddonAction $create): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->forMember($memberId);
        if (!$contract || $contract->status !== 'signed') {
            return $this->back($memberId, 'error', 'Dodatek lze vytvořit pouze k podepsané smlouvě.');
        }
        if ($contract->addon) {
            return $this->back($memberId, 'error', 'Dodatek k této smlouvě již existuje.');
        }

        $create->handle($contract->id);

        return $this->back($memberId, 'success', 'Dodatek byl vytvořen.');
    }

    public function sendAddonLink(int $memberId, IssueSignLinkAction $issue): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->forMember($memberId);
        if (!$contract || !$contract->addon) {
            return $this->back($memberId, 'error', 'Dodatek nenalezen.');
        }
        if ($contract->addon_signed) {
            return $this->back($memberId, 'error', 'Dodatek je již podepsán.');
        }

        $result = $issue->handle($contract->id, SignLinkKind::LegacyAddon);

        return $this->back($memberId, 'success', $result['email_sent'] ? self::LINK_SENT : self::LINK_NOEMAIL)
            ->with('addon_link', $result['url']);
    }

    public function deleteAddon(int $contractId, DeleteLegacyAddonAction $delete): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = Contract::findOrFail($contractId);
        if ($contract->addon_signed) {
            return redirect()->back()->with('error', 'Podepsaný dodatek nelze smazat.');
        }

        $delete->handle($contract);

        return $this->back($contract->member_id, 'success', 'Dodatek byl smazán.');
    }

    public function downloadAddon(int $contractId): BinaryFileResponse|RedirectResponse
    {
        $contract = Contract::findOrFail($contractId);

        $isOwn = (auth()->user()?->member_id == $contract->member_id);
        abort_unless($this->can('view_all') || $isOwn, 403);

        $path = ContractStorage::path($contract->addon_pdf_path);
        if (!$path || !file_exists($path)) {
            return redirect()->back()->with('error', 'PDF dodatku není dostupné.');
        }

        return response()->download($path, 'dodatek-' . $contract->contract_no . '.pdf', ['Content-Type' => 'application/pdf']);
    }

    // ── Dodatky tarif / služba / přípojné místo (contract_addons) ───────────

    public function createTariffAddon(Request $request, int $memberId, CreateTariffAddonAction $create): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);
        // Dodatek řídí cenu i cílový tarif → stejné jemné právo jako editace rychlosti (qos_ceil).
        abort_unless($this->aclCheck('edit_all', 'Members_Controller', 'qos_ceil'), 403);

        $data = $request->validate(['speed_class_id' => 'required|integer|exists:speed_classes,id']);

        $contract = $this->contracts->forMember($memberId);
        if (!$contract || $contract->status !== 'signed') {
            return $this->back($memberId, 'error', 'Dodatek – změnu tarifu lze vytvořit jen k podepsané smlouvě.');
        }
        if (!$create->handle($contract->id, (int) $data['speed_class_id'])) {
            return $this->back($memberId, 'error', 'Dodatek se nepodařilo vytvořit.');
        }

        return $this->back($memberId, 'success', 'Dodatek – změna tarifu byl vytvořen (návrh). Zkontrolujte náhled PDF.');
    }

    public function createServiceAddon(int $memberId, Request $request, CreateServiceAddonAction $create): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $data = $request->validate(['fee_id' => 'required|integer', 'action' => 'required|in:add,remove']);

        $contract = $this->contracts->forMember($memberId);
        if (!$contract || $contract->status !== 'signed') {
            return $this->back($memberId, 'error', 'Dodatek – dodatečnou službu lze vytvořit jen k podepsané smlouvě.');
        }
        if (!$create->handle($contract->id, (int) $data['fee_id'], $data['action'])) {
            return $this->back($memberId, 'error', 'Dodatek se nepodařilo vytvořit (služba nenalezena nebo není typu „Dodatečné služby").');
        }

        return $this->back($memberId, 'success', 'Dodatek – dodatečná služba byl vytvořen (návrh). Zkontrolujte náhled PDF.');
    }

    public function createConnectionPointAddon(int $memberId, Request $request, CreateConnectionPointAddonAction $create): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $data = $request->validate([
            'allowed_subnet_id' => 'required|integer',
            'action'            => 'required|in:add,remove',
            'address'           => 'required|string|max:200',
        ], [
            'address.required' => 'Adresa připojení musí být vyplněná (zařízení v podsíti nemá adresu — doplňte ji ručně nebo u zařízení).',
        ]);

        $contract = $this->contracts->forMember($memberId);
        if (!$contract || $contract->status !== 'signed') {
            return $this->back($memberId, 'error', 'Dodatek – přípojné místo lze vytvořit jen k podepsané smlouvě.');
        }
        if (!$create->handle($contract->id, (int) $data['allowed_subnet_id'], $data['action'], (string) ($data['address'] ?? ''))) {
            return $this->back($memberId, 'error', 'Dodatek se nepodařilo vytvořit (přípojné místo nenalezeno nebo nepatří členovi).');
        }

        return $this->back($memberId, 'success', 'Dodatek – přípojné místo byl vytvořen (návrh). Zkontrolujte náhled PDF.');
    }

    public function previewTariffAddon(int $addonId, PdfService $pdf): Response|RedirectResponse
    {
        $addon = $this->addonOrFail($addonId);
        abort_unless($this->canViewAddon($addon), 403);
        return $this->addonPreview($addon, AddonType::TariffChange, $pdf);
    }

    public function previewServiceAddon(int $addonId, PdfService $pdf): Response|RedirectResponse
    {
        $addon = $this->addonOrFail($addonId);
        abort_unless($this->canViewAddon($addon), 403);
        return $this->addonPreview($addon, AddonType::AdditionalService, $pdf);
    }

    public function previewConnectionPointAddon(int $addonId, PdfService $pdf): Response|RedirectResponse
    {
        $addon = $this->addonOrFail($addonId);
        abort_unless($this->canViewAddon($addon), 403);
        return $this->addonPreview($addon, AddonType::ConnectionPoint, $pdf);
    }

    public function downloadTariffAddon(int $addonId): BinaryFileResponse|RedirectResponse
    {
        $addon = $this->addonOrFail($addonId);
        abort_unless($this->canViewAddon($addon), 403);
        return $this->addonDownload($addon, AddonType::TariffChange);
    }

    public function downloadServiceAddon(int $addonId): BinaryFileResponse|RedirectResponse
    {
        $addon = $this->addonOrFail($addonId);
        abort_unless($this->canViewAddon($addon), 403);
        return $this->addonDownload($addon, AddonType::AdditionalService);
    }

    public function downloadConnectionPointAddon(int $addonId): BinaryFileResponse|RedirectResponse
    {
        $addon = $this->addonOrFail($addonId);
        abort_unless($this->canViewAddon($addon), 403);
        return $this->addonDownload($addon, AddonType::ConnectionPoint);
    }

    public function deleteTariffAddon(int $addonId, DeleteAddonAction $delete): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);
        return $this->addonDelete($addonId, AddonType::TariffChange, $delete);
    }

    public function deleteServiceAddon(int $addonId, DeleteAddonAction $delete): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);
        return $this->addonDelete($addonId, AddonType::AdditionalService, $delete);
    }

    public function deleteConnectionPointAddon(int $addonId, DeleteAddonAction $delete): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);
        return $this->addonDelete($addonId, AddonType::ConnectionPoint, $delete);
    }

    public function sendTariffAddonLink(int $addonId, IssueSignLinkAction $issue): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $addon = $this->addonOrFail($addonId);
        $memberId = $addon->contract?->member_id;
        if ($addon->status === 'signed') {
            return $this->back($memberId, 'error', 'Dodatek je již podepsán.');
        }

        $result = $issue->handle((int) $addon->contract_id, SignLinkKind::TariffAddon, $addon);

        return $this->back($memberId, 'success', $result['email_sent'] ? self::LINK_SENT : self::LINK_NOEMAIL)
            ->with(AddonType::TariffChange->linkFlashKey(), $result['url']);
    }

    public function sendServiceAddonLink(int $addonId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);
        return $this->sendAddingAddonLink($addonId, AddonType::AdditionalService, 'služby');
    }

    public function sendConnectionPointAddonLink(int $addonId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);
        return $this->sendAddingAddonLink($addonId, AddonType::ConnectionPoint, 'místa');
    }

    public function issueServiceRemoval(int $addonId, PdfService $pdf): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);
        return $this->issueRemoval($addonId, AddonType::AdditionalService, $pdf, 'služby',
            'Dodatek o zrušení služby byl vydán a odeslán zákazníkovi. Nezapomeň službu deaktivovat v poplatcích člena.');
    }

    public function issueConnectionPointRemoval(int $addonId, PdfService $pdf): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);
        return $this->issueRemoval($addonId, AddonType::ConnectionPoint, $pdf, 'přípojného místa',
            'Dodatek o zrušení přípojného místa byl vydán a odeslán zákazníkovi. Nezapomeň místo odúčtovat v podsítích člena.');
    }

    /** Dodatek podle id (typ se ve správě historicky nekontroluje) nebo 404. */
    private function addonOrFail(int $addonId): ContractAddon
    {
        $addon = ContractAddon::find($addonId);
        if (!$addon) abort(404);
        return $addon;
    }

    /** Admin (view_all) nebo vlastník smlouvy. */
    private function canViewAddon(ContractAddon $addon): bool
    {
        return $this->can('view_all') || (auth()->user()?->member_id == $addon->contract?->member_id);
    }

    private function addonPreview(ContractAddon $addon, AddonType $type, PdfService $pdf): Response
    {
        return response($type->renderPdf($pdf, $addon, true, null), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $type->adminFileBase() . '-preview.pdf"',
        ]);
    }

    private function addonDownload(ContractAddon $addon, AddonType $type): BinaryFileResponse|RedirectResponse
    {
        $path = ContractStorage::path($addon->pdf_path);
        if (!$path || !is_file($path)) {
            return redirect()->back()->with('error', 'PDF dodatku není dostupné.');
        }

        return response()->download($path, $type->adminFileBase() . '-' . $addon->id . '.pdf', ['Content-Type' => 'application/pdf']);
    }

    private function addonDelete(int $addonId, AddonType $type, DeleteAddonAction $delete): RedirectResponse
    {
        $addon = $this->addonOrFail($addonId);
        $memberId = $addon->contract?->member_id;

        return $delete->handle($addon, $type)
            ? $this->back($memberId, 'success', 'Dodatek byl smazán.')
            : $this->back($memberId, 'error', 'Podepsaný dodatek nelze smazat.');
    }

    /** Odkaz k podpisu jen pro PŘIDÁNÍ služby/místa — zrušení vydává poskytovatel bez podpisu. */
    private function sendAddingAddonLink(int $addonId, AddonType $type, string $what): RedirectResponse
    {
        $addon = $this->addonOrFail($addonId);
        $memberId = $addon->contract?->member_id;
        if ($addon->status === 'signed') {
            return $this->back($memberId, 'error', 'Dodatek je již podepsán.');
        }
        if ($addon->service_action !== 'add') {
            return $this->back($memberId, 'error', "Odkaz k podpisu je jen pro přidání {$what}. Zrušení vydává poskytovatel bez podpisu zákazníka.");
        }

        $typed = ContractAddon::where('type', $type->value)->find($addon->id);
        if (!$typed) {
            return $this->back($memberId, 'error', 'Odkaz se nepodařilo vygenerovat.');
        }
        $result = app(IssueSignLinkAction::class)->handle((int) $typed->contract_id, $type->signLinkKind(), $typed);

        return $this->back($memberId, 'success', $result['email_sent'] ? self::LINK_SENT : self::LINK_NOEMAIL)
            ->with($type->linkFlashKey(), $result['url']);
    }

    private function issueRemoval(int $addonId, AddonType $type, PdfService $pdf, string $what, string $success): RedirectResponse
    {
        $addon = $this->addonOrFail($addonId);
        $memberId = $addon->contract?->member_id;
        if ($addon->service_action !== 'remove') {
            return $this->back($memberId, 'error', "Vydat bez podpisu lze jen dodatek o zrušení {$what}.");
        }
        if ($addon->status === 'signed') {
            return $this->back($memberId, 'error', 'Dodatek je již vydán.');
        }
        if (!app(IssueAddonRemovalAction::class)->handle($addon, $type, $pdf)) {
            return $this->back($memberId, 'error', 'Dodatek se nepodařilo vydat.');
        }

        return $this->back($memberId, 'success', $success);
    }

    // ── Smlouva: náhled, stažení, seznam ────────────────────────────────────

    /**
     * Náhled pro admina: nepodepsaná → PDF z aktuálních dat contract_parties
     * (kontrola před odesláním odkazu), podepsaná → uložené finální PDF.
     */
    public function preview(int $contractId, PdfService $pdf): Response|BinaryFileResponse|RedirectResponse
    {
        $contract = Contract::findOrFail($contractId);

        $isOwn = (auth()->user()?->member_id == $contract->member_id);
        abort_unless($this->can('view_all') || $isOwn, 403);

        if ($contract->status === 'signed') {
            $path = ContractStorage::path($contract->pdf_path);
            if (!$path || !file_exists($path)) {
                return redirect()->back()->with('error', 'Podepsané PDF není dostupné.');
            }
            return response()->file($path, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="smlouva-' . $contract->contract_no . '.pdf"',
                'Cache-Control'       => 'no-store',
            ]);
        }

        return response($pdf->renderContractPdf($contract, true, request()), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="smlouva-' . $contract->contract_no . '-nahled.pdf"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function download(int $contractId): BinaryFileResponse|RedirectResponse
    {
        $contract = Contract::findOrFail($contractId);

        $isOwn = (auth()->user()?->member_id == $contract->member_id);
        abort_unless($this->can('view_all') || $isOwn, 403);

        $path = ContractStorage::path($contract->pdf_path);
        if (!$path || !file_exists($path)) {
            return redirect()->back()->with('error', 'PDF smlouvy není dostupné.');
        }

        return response()->download($path, 'smlouva-' . $contract->contract_no . '.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function index(Request $request, ContractListQuery $list): View
    {
        abort_unless($this->can('view_all'), 403);

        $q      = trim((string) $request->input('q', ''));
        $status = $request->input('status', '');

        return view('contracts.index', [
            'contracts'   => $list->paginate($q, (string) $status),
            'q'           => $q,
            'status'      => $status,
            'totalCounts' => $list->statusCounts(),
        ]);
    }
}
