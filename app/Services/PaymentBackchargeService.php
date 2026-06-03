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
    const TYPE_MEMBER_FEE   = 1;
    const CREDIT_ACCOUNT    = 221100;
    const OPERATING_ACCOUNT = 221101;

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
                    // Osvobozený měsíc — nic nestrhávat, ale flag nemá smysl držet, pokud je celý
                    // tarif 0. Reset až po celkovém průchodu.
                    $cursor->modify('first day of next month');
                    continue;
                }

                $currentBalance = (float) DB::table('accounts')
                    ->where('id', $creditAccount->id)
                    ->value('balance');

                if ($currentBalance < $feeAmount) {
                    // Pořád dluh → backcharge končí, flag zůstává.
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

                $cursor->modify('first day of next month');
            }

            // Po dohnání: pokud má stále dost na příští měsíc, odblokuj.
            // Test proti fee aktuálního měsíce (today) — pokud i ten měsíc se dá pokrýt,
            // zákazník je v zelených číslech.
            $finalBalance = (float) DB::table('accounts')
                ->where('id', $creditAccount->id)
                ->value('balance');
            $feeNow = $this->resolveFeeAmount((int) $member->id, (int) $member->type, $today);

            $unblocked = false;
            if ($feeNow <= 0 || $finalBalance >= $feeNow) {
                DB::table('members')
                    ->where('id', $memberId)
                    ->update([
                        'payment_blocked'       => 0,
                        'payment_blocked_since' => null,
                    ]);
                $unblocked = true;
            }

            // Operating balance přepočet (pokud se cokoliv stáhlo)
            $this->recalculateBalance($orgAccount);

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
     * Stejná logika jako DeductFees::deductMemberFees:
     *  1) individuální tarif z members_fees (regular member fee, aktivní pro $date)
     *  2) fallback: default_fee_member_type_2 (zákazník) / _90 (člen) → fees.fee
     */
    private function resolveFeeAmount(int $memberId, int $memberType, string $date): float
    {
        $individual = DB::table('members_fees as mf')
            ->join('fees as f', 'f.id', '=', 'mf.fee_id')
            ->join('enum_types as et', 'et.id', '=', 'f.type_id')
            ->whereRaw('LOWER(et.value) = ?', ['regular member fee'])
            ->where('mf.member_id', $memberId)
            ->where('mf.activation_date', '<=', $date)
            ->where('mf.deactivation_date', '>=', $date)
            ->orderBy('mf.priority')
            ->value('f.fee');

        if ($individual !== null) {
            return (float) $individual;
        }

        $key   = "default_fee_member_type_{$memberType}";
        $feeId = (int) Setting::get($key, 0);
        if ($feeId <= 0) {
            return 0.0;
        }
        return (float) (DB::table('fees')->where('id', $feeId)->value('fee') ?? 0);
    }

    private function recalculateBalance(int $accountId): void
    {
        $incoming = (float) DB::table('transfers')->where('destination_id', $accountId)->sum('amount');
        $outgoing = (float) DB::table('transfers')->where('origin_id', $accountId)->sum('amount');
        DB::table('accounts')->where('id', $accountId)->update(['balance' => $incoming - $outgoing]);
    }
}
