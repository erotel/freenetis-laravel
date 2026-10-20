<?php

namespace Tests\Support;

use App\Domains\Contracts\Actions\IssueSignLinkAction;
use App\Domains\Contracts\Support\AddonType;
use App\Models\ContractAddon;

/** Odkaz k podpisu dodatku podle jeho typu (jako admin akce „poslat odkaz“). */
trait IssuesContractAddonLinks
{
    /** @return array{url:?string, email_sent:bool, email:?string} */
    private function issueAddonLink(int $addonId): array
    {
        $addon = ContractAddon::find($addonId);
        if (!$addon) {
            return ['url' => null, 'email_sent' => false, 'email' => null];
        }
        return app(IssueSignLinkAction::class)->handle((int) $addon->contract_id, AddonType::from($addon->type)->signLinkKind(), $addon);
    }
}
