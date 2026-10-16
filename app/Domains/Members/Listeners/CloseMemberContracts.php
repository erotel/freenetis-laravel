<?php

namespace App\Domains\Members\Listeners;

use App\Domains\Members\Events\MembershipEnded;
use App\Domains\Contracts\Repositories\ContractRepository;

/**
 * Výpověď → podepsané smlouvy „Ukončená" (zůstávají jako právní dokument),
 * rozpracované návrhy smazat. Počet ukončených vrací v event->contractsTerminated.
 */
class CloseMemberContracts
{
    public function __construct(private ContractRepository $contracts)
    {
    }

    public function handle(MembershipEnded $event): void
    {
        $event->contractsTerminated = $this->contracts->terminateSignedForMember($event->memberId, $event->leavingDate, $event->endedBy);
        $this->contracts->purgeUnsignedForMember($event->memberId);
    }
}
