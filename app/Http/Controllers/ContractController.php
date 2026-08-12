<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Member;
use App\Models\User;
use App\Services\ContractService;
use App\Services\Contracts\PdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContractController extends Controller
{
    private const ACL_SECTION = 'Members_Controller';
    private const ACL_VALUE   = 'members';

    public function __construct(private ContractService $contracts) {}

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function show(int $memberId): View
    {
        // Admin vidí vše; jinak musí jít o vlastní profil
        $isOwn   = (auth()->user()?->member_id == $memberId);
        $canEdit = $this->can('edit_all');
        // Admin = může editovat nebo má právo view_all. Obyčejný zákazník (jen vlastní
        // profil, bez ACL) admin NENÍ → neuvidí administrativní historii dodatků.
        $isAdmin = $canEdit || $this->can('view_all');
        abort_unless($isAdmin || $isOwn, 403);

        $member   = Member::findOrFail($memberId);
        $contract = $this->contracts->getByMemberId($memberId);
        $tariffAddons  = $contract ? $this->contracts->tariffAddons($contract->id) : collect();
        $serviceAddons = $contract ? $this->contracts->serviceAddons($contract->id) : collect();
        // Aktivní dodatečné služby člena (pro výběr do dodatku) — z members_fees.
        $assignableServices = \App\Services\AdditionalServicesResolver::items($memberId, now()->toDateString());
        // Dodatky přípojných míst + placená místa člena pro výběr do dodatku.
        $connectionPointAddons = $contract ? $this->contracts->connectionPointAddons($contract->id) : collect();
        // Placená místa + adresa ze zařízení v podsíti (předvyplnění dodatku).
        $assignablePlaces = \App\Services\AllowedSubnetFeesResolver::chargedPlaces($memberId);

        return view('contracts.show', compact(
            'member', 'contract', 'canEdit', 'isAdmin', 'tariffAddons',
            'serviceAddons', 'assignableServices', 'connectionPointAddons', 'assignablePlaces'
        ));
    }

    public function create(int $memberId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $member   = Member::with([
            'users.contacts.enumType',
            'accounts.variableSymbols',
            'addressPoint.street',
            'addressPoint.town',
            'speedClass',
        ])->findOrFail($memberId);

        // Řádný člen (typ 90) smlouvu nemá — dostávají ji jen zákazníci.
        if ((int) $member->type === \App\Helpers\MemberType::REGULAR) {
            return redirect()
                ->route('members.show', $memberId)
                ->with('error', 'Řádný člen (typ 90) nemá smlouvu — vytvoření není povoleno.');
        }

        $existing = $this->contracts->getByMemberId($memberId);
        if ($existing && !in_array($existing->status, ['canceled'])) {
            return redirect()
                ->route('contracts.show', $memberId)
                ->with('error', 'Smlouva pro tohoto člena již existuje (#' . $existing->contract_no . ').');
        }

        if (!$member->speed_class_id) {
            return redirect()
                ->route('members.edit', $memberId)
                ->with('error', 'Před vytvořením smlouvy nastavte třídu rychlosti (QoS) v editaci člena.');
        }

        // Fyzická osoba bez IČO musí mít vyplněné datum narození hlavního uživatele —
        // jinak je v PDF buňka "Datum narození (IČO, DIČ)" prázdná a smlouva je nepoužitelná.
        $mainUser    = $member->users->firstWhere('type', User::MAIN_USER);
        $hasIco      = trim((string) $member->organization_identifier) !== '';
        $birthdayRaw = $mainUser?->birthday;
        $hasBirthday = $birthdayRaw && (string) $birthdayRaw !== '0000-00-00';

        if (!$hasIco && !$hasBirthday) {
            $target = $mainUser
                ? redirect()->route('users.edit', $mainUser->id)
                : redirect()->route('members.edit', $memberId);
            return $target->with(
                'error',
                'Před vytvořením smlouvy vyplňte datum narození hlavního uživatele (nebo IČO u organizace).'
            );
        }

        $contract = $this->contracts->createContract($member);

        return redirect()
            ->route('contracts.show', $memberId)
            ->with('success', 'Smlouva ' . $contract->contract_no . ' byla vytvořena.');
    }

    public function refresh(int $memberId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->getByMemberId($memberId);
        if (!$contract) {
            return redirect()->route('members.show', $memberId)
                ->with('error', 'Smlouva nenalezena.');
        }
        if ($contract->status !== 'draft') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Aktualizace údajů je možná jen u smlouvy ve stavu Návrh. '
                    . 'V ostatních stavech smlouvu nejdřív zrušte a vytvořte novou.');
        }

        $ok = $this->contracts->refreshPartyFromMember($contract);

        return redirect()->route('contracts.show', $memberId)->with(
            $ok ? 'success' : 'error',
            $ok
                ? 'Údaje ve smlouvě byly aktualizovány podle aktuálních údajů člena.'
                : 'Údaje se nepodařilo aktualizovat.'
        );
    }

    public function updateServiceAddress(int $memberId, \Illuminate\Http\Request $request): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->getByMemberId($memberId);
        if (!$contract) {
            return redirect()->route('members.show', $memberId)
                ->with('error', 'Smlouva nenalezena.');
        }
        if ($contract->status !== 'draft') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Místo připojení lze upravit jen u smlouvy ve stavu Návrh.');
        }

        $validated = $request->validate([
            'service_full_address' => 'nullable|string|max:255',
        ]);

        $ok = $this->contracts->updateServiceAddress(
            $contract,
            (string) ($validated['service_full_address'] ?? '')
        );

        return redirect()->route('contracts.show', $memberId)->with(
            $ok ? 'success' : 'error',
            $ok ? 'Místo připojení bylo upraveno.' : 'Místo připojení se nepodařilo upravit.'
        );
    }

    public function cancel(int $memberId, \Illuminate\Http\Request $request): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->getByMemberId($memberId);
        if (!$contract) {
            return redirect()->route('members.show', $memberId)
                ->with('error', 'Smlouva nenalezena.');
        }

        if (!in_array($contract->status, ['draft', 'otp_sent', 'otp_verified'], true)) {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Zrušit lze pouze nepodepsanou smlouvu.');
        }

        $ok = $this->contracts->cancelContract(
            $contract,
            (string) $request->input('reason', '') ?: null
        );

        return redirect()->route('contracts.show', $memberId)->with(
            $ok ? 'success' : 'error',
            $ok
                ? 'Smlouva byla zrušena. Můžete vytvořit novou.'
                : 'Smlouvu se nepodařilo zrušit.'
        );
    }

    public function terminate(int $memberId, \Illuminate\Http\Request $request): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->getByMemberId($memberId);
        if (!$contract) {
            return redirect()->route('members.show', $memberId)
                ->with('error', 'Smlouva nenalezena.');
        }

        if ($contract->status !== 'signed') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Ukončit lze pouze podepsanou smlouvu.');
        }

        $ok = $this->contracts->terminateContract(
            $contract,
            (string) $request->input('reason', '') ?: null,
            (string) $request->input('termination_date', '') ?: null
        );

        return redirect()->route('contracts.show', $memberId)->with(
            $ok ? 'success' : 'error',
            $ok
                ? 'Smlouva byla označena jako ukončená.'
                : 'Smlouvu se nepodařilo ukončit.'
        );
    }

    public function sendLink(int $memberId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->getByMemberId($memberId);
        if (!$contract) {
            return redirect()
                ->route('members.show', $memberId)
                ->with('error', 'Smlouva nenalezena.');
        }

        if (!in_array($contract->status, ['draft', 'otp_sent', 'otp_verified'])) {
            return redirect()
                ->route('contracts.show', $memberId)
                ->with('error', 'Odkaz lze odeslat pouze pro smlouvy ve stavu Návrh nebo Čeká na podpis.');
        }

        $result = $this->contracts->issueAccessLink($contract->id);

        $message = $result['email_sent']
            ? 'Podpisový odkaz byl vygenerován a odeslán na email zákazníka.'
            : 'Podpisový odkaz byl vygenerován. Zákazník nemá email — zkopírujte odkaz ručně.';

        return redirect()
            ->route('contracts.show', $memberId)
            ->with('sign_link', $result['url'])
            ->with('success', $message);
    }

    public function createAddon(int $memberId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->getByMemberId($memberId);
        if (!$contract || $contract->status !== 'signed') {
            return redirect()
                ->route('contracts.show', $memberId)
                ->with('error', 'Dodatek lze vytvořit pouze k podepsané smlouvě.');
        }

        if ($contract->addon) {
            return redirect()
                ->route('contracts.show', $memberId)
                ->with('error', 'Dodatek k této smlouvě již existuje.');
        }

        $this->contracts->createAddon($contract->id);

        return redirect()
            ->route('contracts.show', $memberId)
            ->with('success', 'Dodatek byl vytvořen.');
    }

    public function sendAddonLink(int $memberId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->getByMemberId($memberId);
        if (!$contract || !$contract->addon) {
            return redirect()
                ->route('contracts.show', $memberId)
                ->with('error', 'Dodatek nenalezen.');
        }

        if ($contract->addon_signed) {
            return redirect()
                ->route('contracts.show', $memberId)
                ->with('error', 'Dodatek je již podepsán.');
        }

        $result = $this->contracts->sendAddonLink($contract->id);

        $message = $result['email_sent']
            ? 'Odkaz pro podpis dodatku byl vygenerován a odeslán na email zákazníka.'
            : 'Odkaz pro podpis dodatku byl vygenerován. Zákazník nemá email — zkopírujte odkaz ručně.';

        return redirect()
            ->route('contracts.show', $memberId)
            ->with('addon_link', $result['url'])
            ->with('success', $message);
    }

    public function deleteAddon(int $contractId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = Contract::findOrFail($contractId);

        if ($contract->addon_signed) {
            return redirect()->back()->with('error', 'Podepsaný dodatek nelze smazat.');
        }

        $memberId = $contract->member_id;

        $this->contracts->deleteAddon($contract);

        return redirect()
            ->route('contracts.show', $memberId)
            ->with('success', 'Dodatek byl smazán.');
    }

    public function downloadAddon(int $contractId): BinaryFileResponse|RedirectResponse
    {
        $contract = Contract::findOrFail($contractId);

        $isOwn = (auth()->user()?->member_id == $contract->member_id);
        abort_unless($this->can('view_all') || $isOwn, 403);

        $path = $this->contracts->addonPdfPath($contract);
        if (!$path || !file_exists($path)) {
            return redirect()->back()->with('error', 'PDF dodatku není dostupné.');
        }

        $filename = 'dodatek-' . $contract->contract_no . '.pdf';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    // ── Dodatek: změna tarifu (contract_addons) ─────────────────────────────

    public function createTariffAddon(int $memberId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->getByMemberId($memberId);
        if (!$contract || $contract->status !== 'signed') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Dodatek – změnu tarifu lze vytvořit jen k podepsané smlouvě.');
        }

        $addon = $this->contracts->createTariffChangeAddon($contract->id);
        if (!$addon) {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Dodatek se nepodařilo vytvořit.');
        }

        return redirect()->route('contracts.show', $memberId)
            ->with('success', 'Dodatek – změna tarifu byl vytvořen (návrh). Zkontrolujte náhled PDF.');
    }

    public function previewTariffAddon(int $addonId, PdfService $pdf): Response|RedirectResponse
    {
        $addon = \App\Models\ContractAddon::find($addonId);
        if (!$addon) abort(404);

        $isOwn = (auth()->user()?->member_id == $addon->contract?->member_id);
        abort_unless($this->can('view_all') || $isOwn, 403);

        $bytes = $pdf->renderTariffAddonPdf($addon, true);

        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="dodatek-tarif-preview.pdf"',
        ]);
    }

    public function deleteTariffAddon(int $addonId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $addon = \App\Models\ContractAddon::find($addonId);
        if (!$addon) abort(404);

        $memberId = $addon->contract?->member_id;
        if (!$this->contracts->deleteTariffAddon($addon)) {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Podepsaný dodatek nelze smazat.');
        }

        return redirect()->route('contracts.show', $memberId)
            ->with('success', 'Dodatek byl smazán.');
    }

    public function sendTariffAddonLink(int $addonId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $addon = \App\Models\ContractAddon::find($addonId);
        if (!$addon) abort(404);

        $memberId = $addon->contract?->member_id;
        if ($addon->status === 'signed') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Dodatek je již podepsán.');
        }

        $result = $this->contracts->issueTariffAddonLink($addon->id);
        $message = $result['email_sent']
            ? 'Odkaz pro podpis dodatku byl vygenerován a odeslán na email zákazníka.'
            : 'Odkaz pro podpis dodatku byl vygenerován. Zákazník nemá email — zkopírujte odkaz ručně.';

        return redirect()->route('contracts.show', $memberId)
            ->with('tariff_addon_link', $result['url'])
            ->with('success', $message);
    }

    public function downloadTariffAddon(int $addonId): BinaryFileResponse|RedirectResponse
    {
        $addon = \App\Models\ContractAddon::find($addonId);
        if (!$addon) abort(404);

        $isOwn = (auth()->user()?->member_id == $addon->contract?->member_id);
        abort_unless($this->can('view_all') || $isOwn, 403);

        $path = $this->contracts->tariffAddonPdfPath($addon);
        if (!$path || !is_file($path)) {
            return redirect()->back()->with('error', 'PDF dodatku není dostupné.');
        }

        return response()->download($path, 'dodatek-tarif-' . $addon->id . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    // ── Dodatek: dodatečná služba (contract_addons, type='additional_service') ──

    public function createServiceAddon(int $memberId, Request $request): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $data = $request->validate([
            'fee_id' => 'required|integer',
            'action' => 'required|in:add,remove',
        ]);

        $contract = $this->contracts->getByMemberId($memberId);
        if (!$contract || $contract->status !== 'signed') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Dodatek – dodatečnou službu lze vytvořit jen k podepsané smlouvě.');
        }

        $addon = $this->contracts->createServiceAddon($contract->id, (int) $data['fee_id'], $data['action']);
        if (!$addon) {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Dodatek se nepodařilo vytvořit (služba nenalezena nebo není typu „Dodatečné služby").');
        }

        return redirect()->route('contracts.show', $memberId)
            ->with('success', 'Dodatek – dodatečná služba byl vytvořen (návrh). Zkontrolujte náhled PDF.');
    }

    public function previewServiceAddon(int $addonId, PdfService $pdf): Response|RedirectResponse
    {
        $addon = \App\Models\ContractAddon::find($addonId);
        if (!$addon) abort(404);

        $isOwn = (auth()->user()?->member_id == $addon->contract?->member_id);
        abort_unless($this->can('view_all') || $isOwn, 403);

        $bytes = $pdf->renderServiceAddonPdf($addon, true);

        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="dodatek-sluzba-preview.pdf"',
        ]);
    }

    public function deleteServiceAddon(int $addonId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $addon = \App\Models\ContractAddon::find($addonId);
        if (!$addon) abort(404);

        $memberId = $addon->contract?->member_id;
        if (!$this->contracts->deleteServiceAddon($addon)) {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Podepsaný dodatek nelze smazat.');
        }

        return redirect()->route('contracts.show', $memberId)
            ->with('success', 'Dodatek byl smazán.');
    }

    public function sendServiceAddonLink(int $addonId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $addon = \App\Models\ContractAddon::find($addonId);
        if (!$addon) abort(404);

        $memberId = $addon->contract?->member_id;
        if ($addon->status === 'signed') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Dodatek je již podepsán.');
        }
        if ($addon->service_action !== 'add') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Odkaz k podpisu je jen pro přidání služby. Zrušení vydává poskytovatel bez podpisu zákazníka.');
        }

        $result = $this->contracts->issueServiceAddonLink($addon->id);
        if (!$result['url']) {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Odkaz se nepodařilo vygenerovat.');
        }
        $message = $result['email_sent']
            ? 'Odkaz pro podpis dodatku byl vygenerován a odeslán na email zákazníka.'
            : 'Odkaz pro podpis dodatku byl vygenerován. Zákazník nemá email — zkopírujte odkaz ručně.';

        return redirect()->route('contracts.show', $memberId)
            ->with('service_addon_link', $result['url'])
            ->with('success', $message);
    }

    public function downloadServiceAddon(int $addonId): BinaryFileResponse|RedirectResponse
    {
        $addon = \App\Models\ContractAddon::find($addonId);
        if (!$addon) abort(404);

        $isOwn = (auth()->user()?->member_id == $addon->contract?->member_id);
        abort_unless($this->can('view_all') || $isOwn, 403);

        $path = $this->contracts->serviceAddonPdfPath($addon);
        if (!$path || !is_file($path)) {
            return redirect()->back()->with('error', 'PDF dodatku není dostupné.');
        }

        return response()->download($path, 'dodatek-sluzba-' . $addon->id . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function issueServiceRemoval(int $addonId, PdfService $pdf): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $addon = \App\Models\ContractAddon::find($addonId);
        if (!$addon) abort(404);

        $memberId = $addon->contract?->member_id;
        if ($addon->service_action !== 'remove') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Vydat bez podpisu lze jen dodatek o zrušení služby.');
        }
        if ($addon->status === 'signed') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Dodatek je již vydán.');
        }

        $result = $this->contracts->issueServiceRemoval($addon, $pdf);
        if (!$result) {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Dodatek se nepodařilo vydat.');
        }

        return redirect()->route('contracts.show', $memberId)
            ->with('success', 'Dodatek o zrušení služby byl vydán a odeslán zákazníkovi. Nezapomeň službu deaktivovat v poplatcích člena.');
    }

    // ── Dodatek: přípojné místo (contract_addons, type='connection_point') ──

    public function createConnectionPointAddon(int $memberId, Request $request): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $data = $request->validate([
            'allowed_subnet_id' => 'required|integer',
            'action'            => 'required|in:add,remove',
            'address'           => 'required|string|max:200',
        ], [
            'address.required' => 'Adresa připojení musí být vyplněná (zařízení v podsíti nemá adresu — doplňte ji ručně nebo u zařízení).',
        ]);

        $contract = $this->contracts->getByMemberId($memberId);
        if (!$contract || $contract->status !== 'signed') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Dodatek – přípojné místo lze vytvořit jen k podepsané smlouvě.');
        }

        $addon = $this->contracts->createConnectionPointAddon(
            $contract->id, (int) $data['allowed_subnet_id'], $data['action'], (string) ($data['address'] ?? '')
        );
        if (!$addon) {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Dodatek se nepodařilo vytvořit (přípojné místo nenalezeno nebo nepatří členovi).');
        }

        return redirect()->route('contracts.show', $memberId)
            ->with('success', 'Dodatek – přípojné místo byl vytvořen (návrh). Zkontrolujte náhled PDF.');
    }

    public function previewConnectionPointAddon(int $addonId, PdfService $pdf): Response|RedirectResponse
    {
        $addon = \App\Models\ContractAddon::find($addonId);
        if (!$addon) abort(404);

        $isOwn = (auth()->user()?->member_id == $addon->contract?->member_id);
        abort_unless($this->can('view_all') || $isOwn, 403);

        $bytes = $pdf->renderConnectionPointAddonPdf($addon, true);

        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="dodatek-misto-preview.pdf"',
        ]);
    }

    public function deleteConnectionPointAddon(int $addonId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $addon = \App\Models\ContractAddon::find($addonId);
        if (!$addon) abort(404);

        $memberId = $addon->contract?->member_id;
        if (!$this->contracts->deleteConnectionPointAddon($addon)) {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Podepsaný dodatek nelze smazat.');
        }

        return redirect()->route('contracts.show', $memberId)
            ->with('success', 'Dodatek byl smazán.');
    }

    public function sendConnectionPointAddonLink(int $addonId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $addon = \App\Models\ContractAddon::find($addonId);
        if (!$addon) abort(404);

        $memberId = $addon->contract?->member_id;
        if ($addon->status === 'signed') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Dodatek je již podepsán.');
        }
        if ($addon->service_action !== 'add') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Odkaz k podpisu je jen pro přidání místa. Zrušení vydává poskytovatel bez podpisu zákazníka.');
        }

        $result = $this->contracts->issueConnectionPointAddonLink($addon->id);
        if (!$result['url']) {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Odkaz se nepodařilo vygenerovat.');
        }
        $message = $result['email_sent']
            ? 'Odkaz pro podpis dodatku byl vygenerován a odeslán na email zákazníka.'
            : 'Odkaz pro podpis dodatku byl vygenerován. Zákazník nemá email — zkopírujte odkaz ručně.';

        return redirect()->route('contracts.show', $memberId)
            ->with('connection_point_addon_link', $result['url'])
            ->with('success', $message);
    }

    public function downloadConnectionPointAddon(int $addonId): BinaryFileResponse|RedirectResponse
    {
        $addon = \App\Models\ContractAddon::find($addonId);
        if (!$addon) abort(404);

        $isOwn = (auth()->user()?->member_id == $addon->contract?->member_id);
        abort_unless($this->can('view_all') || $isOwn, 403);

        $path = $this->contracts->serviceAddonPdfPath($addon);
        if (!$path || !is_file($path)) {
            return redirect()->back()->with('error', 'PDF dodatku není dostupné.');
        }

        return response()->download($path, 'dodatek-misto-' . $addon->id . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function issueConnectionPointRemoval(int $addonId, PdfService $pdf): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $addon = \App\Models\ContractAddon::find($addonId);
        if (!$addon) abort(404);

        $memberId = $addon->contract?->member_id;
        if ($addon->service_action !== 'remove') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Vydat bez podpisu lze jen dodatek o zrušení přípojného místa.');
        }
        if ($addon->status === 'signed') {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Dodatek je již vydán.');
        }

        $result = $this->contracts->issueConnectionPointRemoval($addon, $pdf);
        if (!$result) {
            return redirect()->route('contracts.show', $memberId)
                ->with('error', 'Dodatek se nepodařilo vydat.');
        }

        return redirect()->route('contracts.show', $memberId)
            ->with('success', 'Dodatek o zrušení přípojného místa byl vydán a odeslán zákazníkovi. Nezapomeň místo odúčtovat v podsítích člena.');
    }

    /**
     * Admin PDF náhled — pro nepodepsanou smlouvu vygeneruje preview PDF podle
     * aktuálních dat v contract_parties (bez HMAC tokenu, chráněno auth+ACL).
     * Admin si tak může předem zkontrolovat, jestli je vše OK, ještě než pošle
     * odkaz zákazníkovi. Pro podepsané smlouvy vrací finální uložené PDF.
     */
    public function preview(int $contractId, PdfService $pdf): Response|BinaryFileResponse|RedirectResponse
    {
        $contract = Contract::findOrFail($contractId);

        $isOwn = (auth()->user()?->member_id == $contract->member_id);
        abort_unless($this->can('view_all') || $isOwn, 403);

        if ($contract->status === 'signed') {
            $path = $this->contracts->pdfPath($contract);
            if (!$path || !file_exists($path)) {
                return redirect()->back()->with('error', 'Podepsané PDF není dostupné.');
            }
            return response()->file($path, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="smlouva-' . $contract->contract_no . '.pdf"',
                'Cache-Control'       => 'no-store',
            ]);
        }

        $bytes = $pdf->renderContractPdf($contract, true, request());
        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="smlouva-' . $contract->contract_no . '-nahled.pdf"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function download(int $contractId): BinaryFileResponse|RedirectResponse
    {
        $contract = Contract::findOrFail($contractId);

        // Admin vidí vše; jinak musí jít o smlouvu vlastního profilu
        $isOwn = (auth()->user()?->member_id == $contract->member_id);
        abort_unless($this->can('view_all') || $isOwn, 403);

        $path = $this->contracts->pdfPath($contract);
        if (!$path || !file_exists($path)) {
            return redirect()->back()->with('error', 'PDF smlouvy není dostupné.');
        }

        $filename = 'smlouva-' . $contract->contract_no . '.pdf';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function index(\Illuminate\Http\Request $request): View
    {
        abort_unless($this->can('view_all'), 403);

        $q      = trim((string) $request->input('q', ''));
        $status = $request->input('status', '');

        // Vyloučit smlouvy patřící bývalým členům/zákazníkům (typ 15/16). Smlouvy
        // bez přiřazeného člena (member_id IS NULL) v seznamu zůstávají.
        // Cross-DB query — contracts jsou v contractsdb, members v freenetis.
        $formerMemberIds = \Illuminate\Support\Facades\DB::connection('mysql')
            ->table('members')
            ->whereIn('type', [\App\Helpers\MemberType::FORMER, \App\Helpers\MemberType::FORMER_CUSTOMER])
            ->pluck('id')
            ->all();

        // Bez filtru active=true — u draft smluv je party.active=0 dokud se nepodepíše,
        // a view by jinak padal na fallback 'Člen #XXXX' místo plného jména.
        $query = Contract::with('parties')->orderByDesc('id');

        if (!empty($formerMemberIds)) {
            $query->where(function ($w) use ($formerMemberIds) {
                $w->whereNull('member_id')
                  ->orWhereNotIn('member_id', $formerMemberIds)
                  ->orWhere('status', 'terminated'); // ukončené (výpověď) zůstávají viditelné
            });
        }

        if ($q !== '') {
            // Hledá v čísle smlouvy, jméně strany, VS i (pokud je číslo) v member_id.
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $query->where(function ($w) use ($like, $q) {
                $w->where('contract_no', 'like', $like)
                  ->orWhereHas('parties', function ($pq) use ($like) {
                      $pq->where('full_name', 'like', $like)
                         ->orWhere('variable_symbol', 'like', $like);
                  });
                if (ctype_digit($q)) {
                    $w->orWhere('member_id', (int) $q);
                }
            });
        }

        if ($status !== '' && in_array($status, ['draft', 'otp_sent', 'otp_verified', 'signed', 'canceled', 'terminated'], true)) {
            $query->where('status', $status);
        }

        $contracts = $query->paginate(50)->withQueryString();

        // Globální počty po stavech (bez ohledu na hledání, ale se stejným vyloučením
        // bývalých členů jako hlavní seznam — aby čísla v metrice nematchovala víc, než
        // je v tabulce reálně zobrazené).
        $countsQuery = Contract::selectRaw('status, COUNT(*) AS cnt')->groupBy('status');
        if (!empty($formerMemberIds)) {
            $countsQuery->where(function ($w) use ($formerMemberIds) {
                $w->whereNull('member_id')
                  ->orWhereNotIn('member_id', $formerMemberIds)
                  ->orWhere('status', 'terminated');
            });
        }
        $totalCounts = $countsQuery->pluck('cnt', 'status')->all();

        return view('contracts.index', compact('contracts', 'q', 'status', 'totalCounts'));
    }
}
