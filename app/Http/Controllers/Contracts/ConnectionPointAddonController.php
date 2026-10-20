<?php

namespace App\Http\Controllers\Contracts;

use App\Domains\Contracts\Actions\CreateConnectionPointAddonAction;
use App\Domains\Contracts\Support\AddonType;
use App\Services\Contracts\PdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Dodatek – přípojné místo (přidání s podpisem, zrušení vydá poskytovatel). */
class ConnectionPointAddonController extends AddonController
{
    protected function type(): AddonType
    {
        return AddonType::ConnectionPoint;
    }

    public function store(int $memberId, Request $request, CreateConnectionPointAddonAction $create): RedirectResponse
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

    public function sendLink(int $addonId): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);
        return $this->sendAddingLink($addonId, 'místa');
    }

    public function issueRemoval(int $addonId, PdfService $pdf): RedirectResponse
    {
        abort_unless($this->can('edit_all'), 403);
        return $this->issueRemovalOf($addonId, $pdf, 'přípojného místa',
            'Dodatek o zrušení přípojného místa byl vydán a odeslán zákazníkovi. Nezapomeň místo odúčtovat v podsítích člena.');
    }
}
