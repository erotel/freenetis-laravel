<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Billing\Actions\ChargeMonthlyFeeNowAction;
use App\Domains\Members\Exceptions\MemberNotRestorable;
use App\Domains\Members\Repositories\MemberDeviceRepository;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Repositories\MembershipInterruptRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Obnovení přerušeného členství k datu (dnes nebo budoucí):
 *  - člen bez data odchodu, odemčený, prepaid flagy vynulované
 *  - přerušení končí dnem před obnovou, plánované ukončení po přerušení zrušeno
 *  - přesměrování IP člena smazána
 *  - hned se strhne měsíční poplatek k datu obnovy ({@see ChargeMonthlyFeeNowAction});
 *    padne-li datum na deduct_day, srážka díky idempotenci nestrhne podruhé.
 * Oprávnění kontroluje volající.
 */
class ResumeInterruptedMembershipAction
{
    public function __construct(
        private MemberRepository $members,
        private MembershipInterruptRepository $interrupts,
        private MemberDeviceRepository $devices,
        private ChargeMonthlyFeeNowAction $chargeFee,
    ) {
    }

    /**
     * @return string výsledek srážky (ChargeMonthlyFeeNowAction::CHARGED | SKIPPED_BLOCKED | NOOP)
     * @throws MemberNotRestorable
     */
    public function handle(object $member, string $effectiveDate, ?int $userId): string
    {
        $id = (int) $member->id;
        $interruptFeeId = $this->interrupts->activeInterruptFeeId($id, now()->toDateString());
        if (!$interruptFeeId) {
            throw MemberNotRestorable::notInterrupted();
        }
        $lastInterruptDay = Carbon::parse($effectiveDate)->subDay()->toDateString();

        $result = DB::transaction(function () use ($id, $member, $interruptFeeId, $effectiveDate, $lastInterruptDay, $userId) {
            $this->members->markResumed($id);
            $this->interrupts->endInterrupt($interruptFeeId, $lastInterruptDay);
            $this->devices->clearRedirects($id);

            return $this->chargeFee->handle($id, (int) $member->type, $effectiveDate, $userId);
        });

        AuditLogger::log('updated', 'members', $id, null, [
            'note'      => 'obnovení přerušení členství',
            'effective' => $effectiveDate,
        ]);

        return $result;
    }
}
