<?php

namespace App\Http\Controllers\Contracts;

use App\Domains\Contracts\Actions\DeleteAddonAction;
use App\Domains\Contracts\Actions\IssueAddonRemovalAction;
use App\Domains\Contracts\Actions\IssueSignLinkAction;
use App\Domains\Contracts\Repositories\ContractAddonRepository;
use App\Domains\Contracts\Repositories\ContractRepository;
use App\Domains\Contracts\Support\AddonType;
use App\Domains\Contracts\Support\ContractStorage;
use App\Http\Controllers\Controller;
use App\Models\ContractAddon;
use App\Services\Contracts\PdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Společný základ dodatků v contract_addons (tarif, dodatečná služba,
 * přípojné místo): náhled, stažení, smazání návrhu, odkaz k podpisu,
 * vydání dodatku o zrušení bez podpisu. Potomek určuje typ.
 */
abstract class AddonController extends Controller
{
    use ContractAccess;

    protected const LINK_SENT    = 'Odkaz pro podpis dodatku byl vygenerován a odeslán na email zákazníka.';
    protected const LINK_NOEMAIL = 'Odkaz pro podpis dodatku byl vygenerován. Zákazník nemá email — zkopírujte odkaz ručně.';

    public function __construct(protected ContractRepository $contracts, protected ContractAddonRepository $addons) {}

    abstract protected function type(): AddonType;

    public function preview(int $addonId, PdfService $pdf): Response|RedirectResponse
    {
        $addon = $this->addonOrFail($addonId);
        abort_unless($this->canViewAddon($addon), 403);

        return response($this->type()->renderPdf($pdf, $addon, true, null), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $this->type()->adminFileBase() . '-preview.pdf"',
        ]);
    }

    public function download(int $addonId): BinaryFileResponse|RedirectResponse
    {
        $addon = $this->addonOrFail($addonId);
        abort_unless($this->canViewAddon($addon), 403);

        $path = ContractStorage::path($addon->pdf_path);
        if (!$path || !is_file($path)) {
            return redirect()->back()->with('error', 'PDF dodatku není dostupné.');
        }

        return response()->download($path, $this->type()->adminFileBase() . '-' . $addon->id . '.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function destroy(int $addonId, DeleteAddonAction $delete): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);

        $addon = $this->addonOrFail($addonId);
        $memberId = $addon->contract?->member_id;

        return $delete->handle($addon, $this->type())
            ? $this->back($memberId, 'success', 'Dodatek byl smazán.')
            : $this->back($memberId, 'error', 'Podepsaný dodatek nelze smazat.');
    }

    /** Dodatek daného typu podle id, jinak 404 (cizí typ přes URL jiného dodatku neprojde). */
    protected function addonOrFail(int $addonId): ContractAddon
    {
        $addon = $this->addons->find($addonId, $this->type());
        if (!$addon) abort(404);
        return $addon;
    }

    /** Admin (view_all) nebo vlastník smlouvy. */
    protected function canViewAddon(ContractAddon $addon): bool
    {
        return $this->can('view_all') || (auth()->user()?->member_id == $addon->contract?->member_id);
    }

    /** Odkaz k podpisu jen pro PŘIDÁNÍ služby/místa — zrušení vydává poskytovatel bez podpisu. */
    protected function sendAddingLink(int $addonId, string $what): RedirectResponse
    {
        $type = $this->type();
        $addon = $this->addonOrFail($addonId);
        $memberId = $addon->contract?->member_id;
        if ($addon->status === 'signed') {
            return $this->back($memberId, 'error', 'Dodatek je již podepsán.');
        }
        if ($addon->service_action !== 'add') {
            return $this->back($memberId, 'error', "Odkaz k podpisu je jen pro přidání {$what}. Zrušení vydává poskytovatel bez podpisu zákazníka.");
        }

        $result = app(IssueSignLinkAction::class)->handle((int) $addon->contract_id, $type->signLinkKind(), $addon);

        return $this->back($memberId, 'success', $result['email_sent'] ? self::LINK_SENT : self::LINK_NOEMAIL)
            ->with($type->linkFlashKey(), $result['url']);
    }

    protected function issueRemovalOf(int $addonId, PdfService $pdf, string $what, string $success): RedirectResponse
    {
        $addon = $this->addonOrFail($addonId);
        $memberId = $addon->contract?->member_id;
        if ($addon->service_action !== 'remove') {
            return $this->back($memberId, 'error', "Vydat bez podpisu lze jen dodatek o zrušení {$what}.");
        }
        if ($addon->status === 'signed') {
            return $this->back($memberId, 'error', 'Dodatek je již vydán.');
        }
        if (!app(IssueAddonRemovalAction::class)->handle($addon, $this->type(), $pdf)) {
            return $this->back($memberId, 'error', 'Dodatek se nepodařilo vydat.');
        }

        return $this->back($memberId, 'success', $success);
    }
}
