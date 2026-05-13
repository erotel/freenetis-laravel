<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Member;
use App\Services\ContractService;
use Illuminate\Http\RedirectResponse;
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
        abort_unless($canEdit || $this->can('view_all') || $isOwn, 403);

        $member   = Member::findOrFail($memberId);
        $contract = $this->contracts->getByMemberId($memberId);

        return view('contracts.show', compact('member', 'contract', 'canEdit'));
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

        $contract = $this->contracts->createContract($member);

        return redirect()
            ->route('contracts.show', $memberId)
            ->with('success', 'Smlouva ' . $contract->contract_no . ' byla vytvořena.');
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

        $query = Contract::with(['parties' => fn($qb) => $qb->where('active', true)])
            ->orderByDesc('id');

        if (!empty($formerMemberIds)) {
            $query->where(function ($w) use ($formerMemberIds) {
                $w->whereNull('member_id')
                  ->orWhereNotIn('member_id', $formerMemberIds);
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

        if ($status !== '' && in_array($status, ['draft', 'otp_sent', 'otp_verified', 'signed', 'canceled'], true)) {
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
                  ->orWhereNotIn('member_id', $formerMemberIds);
            });
        }
        $totalCounts = $countsQuery->pluck('cnt', 'status')->all();

        return view('contracts.index', compact('contracts', 'q', 'status', 'totalCounts'));
    }
}
