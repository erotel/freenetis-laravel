<?php

namespace App\Http\Controllers\Contracts;

use Illuminate\Http\RedirectResponse;

/** Společné pro controllery smluv: ACL (jako správa členů) a návrat na kartu smlouvy. */
trait ContractAccess
{
    protected function can(string $action): bool
    {
        return $this->aclCheck($action, 'Members_Controller', 'members');
    }

    protected function back(int $memberId, string $type, string $message): RedirectResponse
    {
        return redirect()->route('contracts.show', $memberId)->with($type, $message);
    }
}
