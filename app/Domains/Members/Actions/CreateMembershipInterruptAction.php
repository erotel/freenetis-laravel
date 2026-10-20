<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Members\Repositories\MembershipInterruptRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Přerušení členství = poplatek „přerušení" (special_type 1, nulový) na
 * interval + membership_interrupts. S „konec po přerušení" se leaving_date
 * nastaví na konec přerušení.
 */
class CreateMembershipInterruptAction
{
    public function __construct(private MembershipInterruptRepository $interrupts)
    {
    }

    public function handle(int $memberId, Carbon $from, Carbon $to, string $comment, bool $endAfter): void
    {
        $feeId = $this->interrupts->interruptFeeId();
        abort_if(!$feeId, 500, 'Membership interrupt fee not found (special_type_id=1).');

        DB::transaction(function () use ($memberId, $feeId, $from, $to, $comment, $endAfter) {
            $this->interrupts->create($memberId, $feeId, $from->toDateString(), $to->toDateString(), $comment, $endAfter);
            if ($endAfter) {
                $this->interrupts->setLeavingDate($memberId, $to->toDateString());
            }
        });

        AuditLogger::log('created', 'membership_interrupts', $memberId, null, [
            'from'      => $from->toDateString(),
            'to'        => $to->toDateString(),
            'end_after' => $endAfter,
        ]);
    }
}
