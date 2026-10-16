<?php

namespace App\Domains\Members\Listeners;

use App\Domains\Members\Events\MemberPurged;
use App\Domains\Contracts\Repositories\ContractRepository;

/** Po smazání člena smazat jeho nepodepsané smlouvy; podepsané/ukončené zůstávají jako právní dokument. */
class PurgeMemberContracts
{
    public function __construct(private ContractRepository $contracts)
    {
    }

    public function handle(MemberPurged $event): void
    {
        $this->contracts->purgeUnsignedForMember($event->memberId);
    }
}
