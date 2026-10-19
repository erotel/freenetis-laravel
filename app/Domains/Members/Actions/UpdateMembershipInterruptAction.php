<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Repositories\MembershipInterruptRepository;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Úprava přerušení. „Konec po přerušení" nastaví leaving_date na konec
 * přerušení (jen pokud odchod není v minulosti). Bez něj se vrací jen
 * odchod, který nastavilo toto přerušení (= jeho dosavadní konec), na
 * „bez odchodu" 9999-12-31 — jiný budoucí odchod (výpovědní lhůta) zůstává.
 */
class UpdateMembershipInterruptAction
{
    public function __construct(private MembershipInterruptRepository $interrupts)
    {
    }

    public function handle(object $interrupt, Carbon $from, Carbon $to, string $comment, bool $endAfter): void
    {
        $today = now()->toDateString();

        DB::transaction(function () use ($interrupt, $from, $to, $comment, $endAfter, $today) {
            $this->interrupts->update($interrupt, $from->toDateString(), $to->toDateString(), $comment, $endAfter);

            $leaving = $this->interrupts->leavingDate((int) $interrupt->member_id);
            if ($endAfter) {
                if (!$leaving || $leaving === '0000-00-00' || $leaving > $today) {
                    $this->interrupts->setLeavingDate((int) $interrupt->member_id, $to->toDateString());
                }
            } elseif ($interrupt->end_after_interrupt_end && $leaving === $interrupt->deactivation_date && $leaving > $today) {
                $this->interrupts->setLeavingDate((int) $interrupt->member_id, MembershipInterruptRepository::NO_LEAVING);
            }
        });

        AuditLogger::log('updated', 'membership_interrupts', (int) $interrupt->member_id, null, [
            'interrupt_id' => $interrupt->id,
            'from'         => $from->toDateString(),
            'to'           => $to->toDateString(),
            'end_after'    => $endAfter,
        ]);
    }
}
