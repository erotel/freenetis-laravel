<?php

namespace App\Http\Controllers\Contracts;

use App\Domains\Contracts\Actions\CreateServiceAddonAction;
use App\Domains\Contracts\Support\AddonType;
use App\Services\Contracts\PdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Dodatek – dodatečná služba (přidání s podpisem, zrušení vydá poskytovatel). */
class ServiceAddonController extends AddonController
{
    protected function type(): AddonType
    {
        return AddonType::AdditionalService;
    }

    public function store(int $memberId, Request $request, CreateServiceAddonAction $create): RedirectResponse
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

    public function sendLink(int $addonId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);
        return $this->sendAddingLink($addonId, 'služby');
    }

    public function issueRemoval(int $addonId, PdfService $pdf): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);
        return $this->issueRemovalOf($addonId, $pdf, 'služby',
            'Dodatek o zrušení služby byl vydán a odeslán zákazníkovi. Nezapomeň službu deaktivovat v poplatcích člena.');
    }
}
