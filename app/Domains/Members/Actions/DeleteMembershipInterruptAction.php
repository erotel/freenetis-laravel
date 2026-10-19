<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Repositories\MembershipInterruptRepository;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Smaže přerušení i jeho poplatek. Budoucí odchod, který nastavilo („konec
 * po přerušení", leaving_date = konec přerušení), vrátí na 9999-12-31.
 */
class DeleteMembershipInterruptAction
{
    public function __construct(private MembershipInterruptRepository $interrupts)
    {
    }

    public function handle(object $interrupt): void
    {
        $today = now()->toDateString();

        DB::transaction(function () use ($interrupt, $today) {
            $leaving = $this->interrupts->leavingDate((int) $interrupt->member_id);
            if ($interrupt->end_after_interrupt_end && $leaving === $interrupt->deactivation_date && $leaving > $today) {
                $this->interrupts->setLeavingDate((int) $interrupt->member_id, MembershipInterruptRepository::NO_LEAVING);
            }
            $this->interrupts->delete($interrupt);
        });

        AuditLogger::log('deleted', 'membership_interrupts', (int) $interrupt->member_id, ['interrupt_id' => $interrupt->id], null);
    }
}
