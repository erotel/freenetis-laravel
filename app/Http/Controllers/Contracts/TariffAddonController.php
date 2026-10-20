<?php

namespace App\Http\Controllers\Contracts;

use App\Domains\Contracts\Actions\CreateTariffAddonAction;
use App\Domains\Contracts\Actions\IssueSignLinkAction;
use App\Domains\Contracts\Support\AddonType;
use App\Domains\Contracts\Support\SignLinkKind;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Dodatek – změna tarifu (contract_addons typu tariff). */
class TariffAddonController extends AddonController
{
    protected function type(): AddonType
    {
        return AddonType::TariffChange;
    }

    public function store(Request $request, int $memberId, CreateTariffAddonAction $create): RedirectResponse
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

    public function sendLink(int $addonId, IssueSignLinkAction $issue): RedirectResponse
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
}
