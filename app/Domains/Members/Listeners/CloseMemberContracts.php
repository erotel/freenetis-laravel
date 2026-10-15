<?php

namespace App\Domains\Members\Listeners;

use App\Domains\Members\Events\MembershipEnded;
use App\Domains\Members\Repositories\MemberContractRepository;

/**
 * Výpověď → podepsané smlouvy „Ukončená" (zůstávají jako právní dokument),
 * rozpracované návrhy smazat. Počet ukončených vrací v event->contractsTerminated.
 */
class CloseMemberContracts
{
    public function __construct(private MemberContractRepository $contracts)
    {
    }

    public function handle(MembershipEnded $event): void
    {
        $event->contractsTerminated = $this->contracts->terminateSigned($event->memberId, $event->leavingDate, $event->endedBy);
        $this->contracts->purgeUnsigned($event->memberId);
    }
}
