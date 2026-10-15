<?php

namespace App\Domains\Members\Listeners;

use App\Domains\Members\Events\MemberPurged;
use App\Domains\Members\Repositories\MemberContractRepository;

/** Po smazání člena smazat jeho nepodepsané smlouvy; podepsané/ukončené zůstávají jako právní dokument. */
class PurgeMemberContracts
{
    public function __construct(private MemberContractRepository $contracts)
    {
    }

    public function handle(MemberPurged $event): void
    {
        $this->contracts->purgeUnsigned($event->memberId);
    }
}
