<?php

namespace App\Http\Controllers\Contracts;

use App\Domains\Contracts\Actions\CreateLegacyAddonAction;
use App\Domains\Contracts\Actions\DeleteLegacyAddonAction;
use App\Domains\Contracts\Actions\IssueSignLinkAction;
use App\Domains\Contracts\Repositories\ContractRepository;
use App\Domains\Contracts\Support\ContractStorage;
use App\Domains\Contracts\Support\SignLinkKind;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Starý dodatek smlouvy (příznak contracts.addon, PDF v contracts.addon_pdf_path). */
class LegacyAddonController extends Controller
{
    use ContractAccess;

    private const LINK_SENT    = 'Odkaz pro podpis dodatku byl vygenerován a odeslán na email zákazníka.';
    private const LINK_NOEMAIL = 'Odkaz pro podpis dodatku byl vygenerován. Zákazník nemá email — zkopírujte odkaz ručně.';

    public function __construct(private ContractRepository $contracts) {}

    public function store(int $memberId, CreateLegacyAddonAction $create): RedirectResponse
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

    public function sendLink(int $memberId, IssueSignLinkAction $issue): RedirectResponse
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

    public function destroy(int $contractId, DeleteLegacyAddonAction $delete): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $contract = $this->contracts->findOrFail($contractId);
        if ($contract->addon_signed) {
            return redirect()->back()->with('error', 'Podepsaný dodatek nelze smazat.');
        }

        $delete->handle($contract);

        return $this->back($contract->member_id, 'success', 'Dodatek byl smazán.');
    }

    public function download(int $contractId): BinaryFileResponse|RedirectResponse
    {
        $contract = $this->contracts->findOrFail($contractId);

        $isOwn = (auth()->user()?->member_id == $contract->member_id);
        abort_unless($this->can('view_all') || $isOwn, 403);

        $path = ContractStorage::path($contract->addon_pdf_path);
        if (!$path || !file_exists($path)) {
            return redirect()->back()->with('error', 'PDF dodatku není dostupné.');
        }

        return response()->download($path, 'dodatek-' . $contract->contract_no . '.pdf', ['Content-Type' => 'application/pdf']);
    }
}
