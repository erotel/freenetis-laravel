<?php

namespace App\Domains\Members\Actions;

use App\Domains\Billing\Actions\IssueTerminationRefundAction;
use App\Domains\Billing\Repositories\LedgerRepository;
use App\Domains\Billing\Repositories\RefundRepository;
use App\Domains\Billing\Support\TerminationRefund;
use App\Domains\Members\Events\MembershipEnded;
use App\Domains\Members\Exceptions\MembershipNotEndable;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Support\EndMode;
use App\Domains\Members\Support\FormerType;
use App\Models\Member;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\MemberFeesTermination;
use App\Services\PaymentBlockedRedirectService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Ukončení členství (výpověď) k datu odchodu.
 *
 * Odchod DNES (nebo v minulosti): typ bývalého (15/16) hned, zámek portálu,
 * rychlost „žádná", VS +U, z kreditu odejde celý zůstatek (u vratky).
 *
 * Výpovědní lhůta (odchod v budoucnu): člen zůstává svého typu a platí běžné
 * poplatky až do odchodu — typ bývalého, zámek, rychlost a +U dorovná cron
 * members:redirect-former v den D. Vratka je už zkrácená o poplatky ve lhůtě
 * (formulář), takže z kreditu odejde JEN vracená částka a nesmí převýšit
 * kredit. Dřív se typ měnil hned a vymazal se celý kredit → srážka ve lhůtě
 * našla 0 a člena zablokovala (incident .54, člen 6235).
 *
 * Vždy: reset prepaid flagů, ukončení individuálních poplatků k datu odchodu,
 * audit. Vedlejší efekty (e-mail, smlouvy) řeší listenery {@see MembershipEnded}.
 * Oprávnění kontroluje volající.
 */
class EndMembershipAction
{
    public function __construct(
        private MemberRepository $members,
        private LedgerRepository $ledger,
        private RefundRepository $refunds,
        private IssueTerminationRefundAction $issueRefund,
        private RemoveFormerMemberDevicesAction $removeDevices,
        private PaymentBlockedRedirectService $paymentBlockedRedirect,
    ) {
    }

    /**
     * @param int $createdBy uživatel do odchozí platby
     * @throws MembershipNotEndable
     * @throws ModelNotFoundException
     */
    public function handle(int $memberId, string $leavingDate, int $endMode, ?string $refundAccount,
                           ?float $refundAmount, int $createdBy, ?string $endedBy): MembershipEnded
    {
        $member = $this->members->row($memberId)
            ?? throw (new ModelNotFoundException())->setModel(Member::class, [$memberId]);
        $type = (int) $member->type;

        if (FormerType::isFormer($type) || FormerType::inNoticePeriod($member->leaving_date)) {
            throw MembershipNotEndable::alreadyEnded();
        }

        $today      = now()->format('Y-m-d');
        $inNotice   = $leavingDate > $today;
        $formerType = FormerType::for($type);
        $wantRefund = $endMode === EndMode::REFUND;

        if ($inNotice && $wantRefund) {
            $balance = (float) ($this->ledger->creditAccount($memberId)?->balance ?? 0);
            if (round((float) $refundAmount, 2) > round($balance, 2)) {
                throw MembershipNotEndable::refundExceedsCredit($balance);
            }
        }
        if ($wantRefund && !$this->refunds->bankAccountIdFor($type)) {
            throw MembershipNotEndable::refundBankAccountMissing($type);
        }

        // Zámek portálu a rychlost „žádná" jen když odchod už nastal; u budoucího
        // data člen službu dohrává (Kohana members.php:4076) — dorovná cron v den D.
        $leftAlready = $leavingDate <= $today;
        $typeNow     = $inNotice ? $type : $formerType;
        $lockNow     = $leftAlready ? 1 : 0;
        $speedNow    = $leftAlready ? null : $member->speed_class_id;

        $refund = DB::transaction(function () use ($memberId, $member, $type, $typeNow, $lockNow, $speedNow, $leavingDate,
                                                   $endMode, $inNotice, $leftAlready, $wantRefund, $refundAccount, $refundAmount, $createdBy): ?TerminationRefund {
            $this->members->markLeaving($memberId, $typeNow, $lockNow, $leavingDate, $speedNow);
            AuditLogger::log('updated', 'members', $memberId, [
                'type'         => $member->type,
                'locked'       => $member->locked,
                'leaving_date' => $member->leaving_date,
            ], [
                'type'         => $typeNow,
                'locked'       => $lockNow,
                'leaving_date' => $leavingDate,
                'end_mode'     => $endMode,
            ]);
            // Aktivní redirect „nezaplaceno" smazat (flag byl právě resetován).
            $this->paymentBlockedRedirect->refreshForMember($memberId);

            // Individuální tarif a služby datumově ukončit k datu odchodu (i budoucímu).
            MemberFeesTermination::deactivate($memberId, $leavingDate);

            // Ve výpovědní lhůtě +U až cron v den odchodu — do té doby se VS páruje s platbami.
            if (!$inNotice) {
                $this->members->markVariableSymbolLeft($memberId);
            }

            $refund = null;
            if ($wantRefund && !empty($refundAccount) && ($refundAmount ?? 0) > 0) {
                $refund = $this->issueRefund->handle($memberId, $type, $leavingDate, $refundAccount,
                    (float) $refundAmount, $inNotice, $createdBy);
            }

            if ($leftAlready && Setting::get('former_member_auto_device_remove', '0') == '1') {
                $this->removeDevices->handle($memberId, now()->format('Y-m-d'), true);
            }

            return $refund;
        });

        $event = new MembershipEnded($memberId, $type, $formerType, $leavingDate, $endMode, $inNotice, $refund, $endedBy);
        event($event);

        return $event;
    }
}
