<?php

namespace App\Http\Controllers;

use App\Domains\Contracts\Actions\CancelContractAction;
use App\Domains\Contracts\Actions\CreateContractAction;
use App\Domains\Contracts\Actions\IssueSignLinkAction;
use App\Domains\Contracts\Actions\RefreshContractPartyAction;
use App\Domains\Contracts\Actions\TerminateContractAction;
use App\Domains\Contracts\Actions\UpdateServiceAddressAction;
use App\Domains\Contracts\Pdf\PdfService;
use App\Domains\Contracts\Queries\ContractCardQuery;
use App\Domains\Contracts\Queries\ContractListQuery;
use App\Domains\Contracts\Repositories\ContractRepository;
use App\Domains\Contracts\Support\ContractPreconditions;
use App\Domains\Contracts\Support\ContractStorage;
use App\Domains\Contracts\Support\SignLinkKind;
use App\Domains\Members\Repositories\MemberRepository;
use App\Http\Controllers\Contracts\ContractAccess;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Správa smluv a dodatků (karta smlouvy člena, seznam). Logika v app/Domains/Contracts. */
class ContractController extends Controller
{
    use ContractAccess;

    public function __construct(private ContractRepository $contracts) {}

    public function show(int $memberId, ContractCardQuery $card): View
    {
        // Admin (edit_all/view_all) vidí vše; zákazník jen vlastní profil a bez
        // administrativní historie dodatků.
        $isOwn   = (auth()->user()?->member_id == $memberId);
        $canEdit = $this->can('edit_all');
        $isAdmin = $canEdit || $this->can('view_all');
        abort_unless($isAdmin || $isOwn, 403);

        $member = app(MemberRepository::class)->findOrFail($memberId);

        return view('contracts.show', ['member' => $member, 'canEdit' => $canEdit, 'isAdmin' => $isAdmin,
            // Výběr cílového tarifu do dodatku jen s jemným právem qos_ceil.
            'canEditQos' => $this->aclCheck('edit_all', 'Members_Controller', 'qos_ceil'),
        ] + $card->data($memberId));
    }

    public function create(int $memberId, CreateContractAction $create): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $member = app(MemberRepository::class)->findOrFail($memberId)
            ->load(['users.contacts.enumType', 'accounts.variableSymbols', 'addressPoint.street', 'addressPoint.town', 'speedClass']);
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

    // ── Smlouva: náhled, stažení, seznam ────────────────────────────────────

    /**
     * Náhled pro admina: nepodepsaná → PDF z aktuálních dat contract_parties
     * (kontrola před odesláním odkazu), podepsaná → uložené finální PDF.
     */
    public function preview(int $contractId, PdfService $pdf): Response|BinaryFileResponse|RedirectResponse
    {
        $contract = $this->contracts->findOrFail($contractId);

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
        $contract = $this->contracts->findOrFail($contractId);

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
