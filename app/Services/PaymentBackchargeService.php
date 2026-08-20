<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Po přijaté platbě dohnat členské poplatky, které DeductFees přeskočil
 * (payment_blocked=1). Strhává chronologicky od nejstaršího, dokud kredit
 * stačí. Když dohnaný dluh + zůstatek pokryje i příští měsíc → odblokuje.
 *
 * Idempotentní — opakovaný běh ničemu neuškodí (kontroluje existenci
 * transferu pro daný target_date).
 *
 * Volá se z ImportController po vytvoření příchozího transferu.
 */
class PaymentBackchargeService
{
    const TYPE_MEMBER_FEE             = 1;
    const TYPE_ADDITIONAL_SERVICE_FEE = 6;
    const CREDIT_ACCOUNT              = 221100;
    const OPERATING_ACCOUNT           = 221101;

    /**
     * Vrací true pokud došlo k odblokování (payment_blocked 1 → 0),
     * false pokud flag zůstává nebo nebylo co dělat.
     */
    public function backchargeForMember(int $memberId, string $today): bool
    {
        $member = DB::table('members')->where('id', $memberId)->first();
        if (!$member || (int) $member->payment_blocked !== 1) {
            return false;
        }
        if (empty($member->payment_blocked_since) || $member->payment_blocked_since === '0000-00-00') {
            return false;
        }

        $creditAccount = DB::table('accounts')
            ->where('member_id', $memberId)
            ->where('account_attribute_id', self::CREDIT_ACCOUNT)
            ->first();
        if (!$creditAccount) {
            return false;
        }

        $orgAccount = DB::table('accounts')
            ->where('member_id', 1)
            ->where('account_attribute_id', self::OPERATING_ACCOUNT)
            ->value('id');
        if (!$orgAccount) {
            Log::warning('PaymentBackchargeService: organization operating account not found.');
            return false;
        }

        $deductDay = (int) Setting::get('deduct_day', 26);
        $creationDatetime = date('Y-m-d H:i:s');

        DB::beginTransaction();
        try {
            // Iteruj měsíce od payment_blocked_since (resp. od 1. dne toho měsíce)
            // do dnešního měsíce včetně. Pro každý spočítej effective_day
            // (deduct_day omezený na poslední den měsíce) a target_date —
            // přesně to datum, které by DeductFees použil.
            $cursor = new \DateTime($member->payment_blocked_since);
            $cursor->modify('first day of this month');
            $end    = new \DateTime($today);
            $end->modify('last day of this month');

            // Pokud loop skončí brzy kvůli nedostatku kreditu na nějaký dlužný
            // měsíc, flag musí zůstat 1 — pořád se dluží. Pokud projde celé (ať
            // už strhl, nebo přeskočil 'alreadyDeducted'), může se odblokovat.
            $brokeEarly = false;
            $chargedCount = 0; // kolik měsíců/položek se reálně dohnalo (pro audit)

            while ($cursor <= $end) {
                $year         = (int) $cursor->format('Y');
                $month        = (int) $cursor->format('n');
                $lastDay      = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
                $effectiveDay = min($deductDay, $lastDay);
                $targetDate   = sprintf('%04d-%02d-%02d', $year, $month, $effectiveDay);

                // Budoucnost (deduct_day v aktuálním měsíci ještě nepřišel) → konec.
                if ($targetDate > $today) {
                    break;
                }

                // Už strženo (idempotence)? Přeskoč. Pokrývá oba případy:
                //   - DeductFees stihl strhnout v daný den (kredit byl)
                //   - Backcharge to strhl v předchozím běhu
                $alreadyDeducted = DB::table('transfers')
                    ->where('origin_id', $creditAccount->id)
                    ->where('type', self::TYPE_MEMBER_FEE)
                    ->where('datetime', $targetDate)
                    ->exists();
                if ($alreadyDeducted) {
                    $cursor->modify('first day of next month');
                    continue;
                }

                // Fee aktivní pro daný target_date (individuální members_fees má přednost,
                // jinak default podle members.type). Stejná logika jako DeductFees::deductMemberFees.
                $feeAmount = $this->resolveFeeAmount((int) $member->id, (int) $member->type, $targetDate);
                if ($feeAmount <= 0) {
                    // Osvobozený měsíc — nic nestrhávat. Loop pokračuje, flag se případně
                    // zruší po celém průchodu.
                    $cursor->modify('first day of next month');
                    continue;
                }

                $currentBalance = (float) DB::table('accounts')
                    ->where('id', $creditAccount->id)
                    ->value('balance');

                if ($currentBalance < $feeAmount) {
                    // Pořád dluh za tento měsíc → backcharge končí, flag zůstává.
                    $brokeEarly = true;
                    break;
                }

                DB::table('transfers')->insert([
                    'origin_id'         => $creditAccount->id,
                    'destination_id'    => $orgAccount,
                    'type'              => self::TYPE_MEMBER_FEE,
                    'amount'            => $feeAmount,
                    'datetime'          => $targetDate,
                    'creation_datetime' => $creationDatetime,
                    'text'              => 'Dohnání srážky po platbě (prepaid backcharge)',
                    'member_id'         => null,
                    'user_id'           => null,
                ]);
                DB::table('accounts')->where('id', $creditAccount->id)->decrement('balance', $feeAmount);
                $chargedCount++;

                $cursor->modify('first day of next month');
            }

            // Dohnat i dodatečné služby (type=6) — stejný princip jako členský
            // poplatek. Spustí se jen pokud členské poplatky prošly celé; pokud
            // ne, člen tak jako tak zůstává blokovaný a služby dožene příště.
            if (!$brokeEarly) {
                $cursorSvc = new \DateTime($member->payment_blocked_since);
                $cursorSvc->modify('first day of this month');

                while ($cursorSvc <= $end) {
                    $year         = (int) $cursorSvc->format('Y');
                    $month        = (int) $cursorSvc->format('n');
                    $lastDay      = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
                    $effectiveDay = min($deductDay, $lastDay);
                    $targetDate   = sprintf('%04d-%02d-%02d', $year, $month, $effectiveDay);

                    if ($targetDate > $today) {
                        break;
                    }

                    $alreadyDeducted = DB::table('transfers')
                        ->where('origin_id', $creditAccount->id)
                        ->where('type', self::TYPE_ADDITIONAL_SERVICE_FEE)
                        ->where('datetime', $targetDate)
                        ->exists();
                    if ($alreadyDeducted) {
                        $cursorSvc->modify('first day of next month');
                        continue;
                    }

                    $svcAmount = $this->resolveAdditionalServiceAmount((int) $member->id, $targetDate);
                    if ($svcAmount <= 0) {
                        $cursorSvc->modify('first day of next month');
                        continue;
                    }

                    $currentBalance = (float) DB::table('accounts')
                        ->where('id', $creditAccount->id)
                        ->value('balance');

                    if ($currentBalance < $svcAmount) {
                        // Pořád dluh za služby tohoto měsíce → flag zůstává.
                        $brokeEarly = true;
                        break;
                    }

                    DB::table('transfers')->insert([
                        'origin_id'         => $creditAccount->id,
                        'destination_id'    => $orgAccount,
                        'type'              => self::TYPE_ADDITIONAL_SERVICE_FEE,
                        'amount'            => $svcAmount,
                        'datetime'          => $targetDate,
                        'creation_datetime' => $creationDatetime,
                        'text'              => 'Dohnání srážky po platbě – dodatečné služby',
                        'member_id'         => null,
                        'user_id'           => null,
                    ]);
                    DB::table('accounts')->where('id', $creditAccount->id)->decrement('balance', $svcAmount);
                    $chargedCount++;

                    $cursorSvc->modify('first day of next month');
                }
            }

            // Odblokuj pokud backcharge dohnal/přeskočil všechny dlužné měsíce.
            // Zbytková bilance se nekontroluje — příští DeductFees (1. den měsíce)
            // přirozeně zafláguje pokud nebude mít na poplatek. To je správný
            // prepaid cyklus: zaplať dluh → obnovený provoz → příští měsíc
            // znovu zaplať včas, jinak budeš znovu blokovaný.
            $unblocked = false;
            if (!$brokeEarly) {
                DB::table('members')
                    ->where('id', $memberId)
                    ->update([
                        'payment_blocked'       => 0,
                        'payment_blocked_since' => null,
                        'pending_termination'   => 0,
                    ]);
                $unblocked = true;
            }

            // Operating balance přepočet (pokud se cokoliv stáhlo)
            $this->recalculateBalance($orgAccount);

            // Audit trail: souhrn dobírky člena (prepaid backcharge). Systémový
            // proces (user_id=NULL), auditujeme jen pokud se reálně něco dohnalo.
            if ($chargedCount > 0) {
                \App\Services\AuditLogger::log('backcharge', 'members', $memberId, null, [
                    'charged_count' => $chargedCount,
                    'unblocked'     => $unblocked,
                ]);
            }

            DB::commit();
            return $unblocked;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('PaymentBackchargeService error', [
                'member_id' => $memberId,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Stejná logika jako DeductFees::deductMemberFees — centrálně přes
     * RegularFeeResolver: individuální (members_fees) → cena tarifu
     * (speed_classes.price) → základní (default_fee_member_type_<type>).
     */
    private function resolveFeeAmount(int $memberId, int $memberType, string $date): float
    {
        return (float) (RegularFeeResolver::amount($memberId, $memberType, $date) ?? 0.0);
    }

    /**
     * Součet měsíčních příplatků člena (transfer type 6) k danému datu:
     * dodatečné služby (AdditionalServicesResolver) + placená přípojná místa
     * (AllowedSubnetFeesResolver). Shodné se zobrazením na kartě a s bulk SQL
     * v DeductFees::deductAdditionalServiceFees.
     */
    private function resolveAdditionalServiceAmount(int $memberId, string $date): float
    {
        return AdditionalServicesResolver::total($memberId, $date)
            + AllowedSubnetFeesResolver::total($memberId);
    }

    private function recalculateBalance(int $accountId): void
    {
        $incoming = (float) DB::table('transfers')->where('destination_id', $accountId)->sum('amount');
        $outgoing = (float) DB::table('transfers')->where('origin_id', $accountId)->sum('amount');
        DB::table('accounts')->where('id', $accountId)->update(['balance' => $incoming - $outgoing]);
    }
}
