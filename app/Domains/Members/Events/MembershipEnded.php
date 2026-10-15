<?php

namespace App\Domains\Members\Events;

use App\Domains\Billing\Support\TerminationRefund;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Člen podal výpověď / byl ukončen (ruční Ukončit). Při odchodu do budoucna
 * (výpovědní lhůta) má zatím původní typ — bývalý ($formerType) dostane cronem
 * v den odchodu. Dispatch po commitu transakce ukončení.
 */
class MembershipEnded
{
    use Dispatchable;

    /** Výsledek listeneru CloseMemberContracts: počet podepsaných smluv označených jako ukončené. */
    public int $contractsTerminated = 0;

    public function __construct(
        public readonly int $memberId,
        public readonly int $previousType,
        public readonly int $formerType,
        public readonly string $leavingDate,
        public readonly int $endMode,
        public readonly bool $inNoticePeriod,
        public readonly ?TerminationRefund $refund,
        public readonly ?string $endedBy,    // login admina (do událostí smluv)
    ) {
    }
}
