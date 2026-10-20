<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Fees\FeePolicy;
use App\Domains\Billing\Repositories\LedgerRepository;
use App\Domains\Billing\Support\AccountAttribute;
use App\Domains\Billing\Support\TransferType;
use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Prepaid dohnání po přijaté platbě: blokovanému členovi (payment_blocked)
 * strhne poplatky, které měsíční srážka přeskočila — od měsíce
 * payment_blocked_since do dneška, s datem srážky (deduct_day oříznutý na
 * konec měsíce), dokud kredit stačí. Nejdřív členský poplatek za všechny
 * měsíce, pak příplatky (služby, místa) — jen když členské prošly celé.
 * Projde-li vše, blokace končí (zbytek kontroluje příští srážka).
 *
 * Idempotentní: měsíc se strženým převodem stejného typu se přeskočí.
 * Chyba = rollback, log, false.
 */
class ChargeOverdueFeesAction
{
    private const KINDS = [
        TransferType::MEMBER_FEE             => 'Dohnání srážky po platbě (prepaid backcharge)',
        TransferType::ADDITIONAL_SERVICE_FEE => 'Dohnání srážky po platbě – dodatečné služby',
    ];

    public function __construct(
        private LedgerRepository $ledger,
        private FeePolicy $fees,
    ) {
    }

    /** @return bool true = blokace zrušena */
    public function handle(int $memberId, string $today): bool
    {
        $member = DB::table('members')->where('id', $memberId)->first();
        if (!$member || (int) $member->payment_blocked !== 1
            || empty($member->payment_blocked_since) || $member->payment_blocked_since === '0000-00-00') {
            return false;
        }
        $credit = $this->ledger->creditAccount($memberId);
        if (!$credit) {
            return false;
        }
        $operating = $this->ledger->organizationAccountId(AccountAttribute::OPERATING);
        if (!$operating) {
            Log::warning('ChargeOverdueFeesAction: organization operating account not found.');
            return false;
        }

        $deductDay = (int) Setting::get('deduct_day', 26);
        $createdAt = date('Y-m-d H:i:s');

        DB::beginTransaction();
        try {
            $charged = 0;
            $complete = true;
            foreach (self::KINDS as $type => $text) {
                [$ok, $n] = $this->chargeKind((int) $credit->id, $operating, (int) $member->id, (int) $member->type, $type, $text,
                    $member->payment_blocked_since, $today, $deductDay, $createdAt);
                $charged += $n;
                if (!$ok) {
                    $complete = false;
                    break; // dluh trvá → příplatky až příště
                }
            }

            if ($complete) {
                $this->ledger->clearPaymentBlock($memberId);
            }
            $this->ledger->recalculateBalance($operating);

            if ($charged > 0) {
                AuditLogger::log('backcharge', 'members', $memberId, null, ['charged_count' => $charged, 'unblocked' => $complete]);
            }

            DB::commit();
            return $complete;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('ChargeOverdueFeesAction error', ['member_id' => $memberId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** @return array{0: bool, 1: int} [prošlo všemi měsíci, kolik strženo] */
    private function chargeKind(int $creditId, int $operating, int $memberId, int $memberType, int $type, string $text,
                                string $since, string $today, int $deductDay, string $createdAt): array
    {
        $count  = 0;
        $cursor = (new \DateTime($since))->modify('first day of this month');
        $end    = (new \DateTime($today))->modify('last day of this month');

        for (; $cursor <= $end; $cursor->modify('first day of next month')) {
            $target = self::deductDate($cursor, $deductDay);
            if ($target > $today) {
                break; // den srážky aktuálního měsíce ještě nepřišel
            }
            if ($this->ledger->hasTransferAt($creditId, $type, $target)) {
                continue;
            }
            $amount = $this->amount($memberId, $memberType, $type, $target);
            if ($amount <= 0) {
                continue; // osvobozený měsíc
            }
            if ($this->ledger->balance($creditId) < $amount) {
                return [false, $count];
            }
            $this->ledger->postFee($creditId, $operating, $type, $amount, $target, $createdAt, $text);
            $count++;
        }

        return [true, $count];
    }

    /** Datum srážky v měsíci kurzoru: deduct_day oříznutý na poslední den měsíce. */
    private static function deductDate(\DateTime $month, int $deductDay): string
    {
        $y = (int) $month->format('Y');
        $m = (int) $month->format('n');
        $lastDay = (int) date('t', strtotime(sprintf('%04d-%02d-01', $y, $m)));
        return sprintf('%04d-%02d-%02d', $y, $m, min($deductDay, $lastDay));
    }

    /** Dlužná částka k datu; žadatel/čekající/bývalý se neúčtuje (dostal by budoucí cenu). */
    private function amount(int $memberId, int $memberType, int $type, string $date): float
    {
        if (!FeePolicy::isBilled($memberType)) {
            return 0.0;
        }
        return $type === TransferType::MEMBER_FEE
            ? (float) ($this->fees->regularFee($memberId, $memberType, $date) ?? 0.0)
            : $this->fees->surcharges($memberId, $date);
    }
}
