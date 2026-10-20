<?php

namespace App\Domains\Billing\Fees;

use App\Domains\Billing\Repositories\FeeRepository;
use App\Domains\Billing\Repositories\LedgerRepository;
use App\Models\Account;
use App\Models\Setting;

/**
 * „Zaplaceno do“: od poslední srážky (nebo vstupu) odečítá měsíční poplatky
 * stejně jako reálná srážka (FeePolicy) + splátky vstupního a zařízení,
 * dokud kredit stačí (u dluhu zpětně).
 */
class PaidUntil
{
    public function __construct(private LedgerRepository $ledger, private FeeRepository $fees)
    {
    }

    /**
     * „Zaplaceno do“ kreditního účtu — port Kohana Members_Controller::get_expiration_date().
     * Datum (Y-m-d), nebo null, když se nic nestrhává / nelze určit.
     */
    public function for(Account $account): ?string
    {
        $balance  = (float) $account->balance;
        $memberId = $account->member_id;

        // Deduct day from settings (default 26)
        $deductDay = (int) Setting::get('deduct_day', 26);

        // Last DEDUCT transfer (fee deductions only, origin = this account)
        // Types: 1=DEDUCT_MEMBER_FEE, 2=DEDUCT_ENTRANCE_FEE, 5=DEDUCT_DEVICE_FEE
        $lastTransfer = $this->ledger->lastDeductionAt((int) $account->id);

        // Start date: last transfer or member entrance date
        $member = $account->member;
        $startStr = $lastTransfer ?? $member?->entrance_date ?? now()->toDateString();
        $startDate = \Carbon\Carbon::parse($startStr);

        // Round start to closest deduct date
        [$month, $year] = $this->closestDeductDate($startDate, $deductDay);

        $sign = $balance >= 0 ? 1 : -1;

        // Collect debt payments (entrance fee + devices)
        $payments = [];
        if ($member) {
            $entranceDate = \Carbon\Carbon::parse($member->entrance_date ?? now());
            [$em, $ey] = $this->closestDeductDate($entranceDate, $deductDay);
            $debtRate = ($member->debt_payment_rate > 0) ? (float)$member->debt_payment_rate : (float)$member->entrance_fee;
            $this->collectDebtPayments($payments, $em, $ey, (float)$member->entrance_fee, $debtRate);

            // Device debt payments
            $devices = $this->fees->devicesWithDebt((int) $memberId);

            foreach ($devices as $device) {
                $buyDate = \Carbon\Carbon::parse($device->buy_date);
                [$dm, $dy] = $this->closestDeductDate($buyDate, $deductDay);
                $this->collectDebtPayments($payments, $dm, $dy, (float)$device->price, (float)$device->payment_rate);
            }
        }

        // Fee date bounds
        [$minFeeRow, $maxFeeRow] = $this->fees->regularFeeBounds();

        $maxIterations = 600; // ~50 years

        // Poplatek se rozlišuje SHODNĚ s reálnou srážkou (Billing\Fees\FeePolicy::regularFee,
        // který mirroruje DeductFees): individuální (members_fees) → cena tarifu
        // (speed_classes.price) → základní dle typu. Tarif a základní jsou v rámci člena
        // konstantní → předpočítáme je před cyklem (iteruje se až 600×); jen individuální
        // závisí na datu, řeší se per měsíc. Dřív to bralo jen members_fees(member) →
        // fallback member_id=1 default (320), takže „Zaplaceno do" nesedělo s tím, co se
        // reálně strhává u členů s tarifním poplatkem.
        $memberType = (int) ($member->type ?? 0);
        $feePolicy  = app(FeePolicy::class);
        $tarifFee   = $feePolicy->tarifPrice($memberId);
        $defaultFee = $feePolicy->defaultFeeByType($memberType);
        // Typ mimo účtování (žadatel, čekající, bývalý) — nic se nestrhává → „—".
        $billed     = FeePolicy::isBilled($memberType);
        // Placená přípojná místa jsou v čase konstantní; služby závisí na datu.
        $placesFee  = AllowedSubnetFeesResolver::total($memberId);

        $charged = false; // strhává se v některém měsíci vůbec něco?
        for ($i = 0; $i < $maxIterations; $i++) {
            $day = min($deductDay, cal_days_in_month(CAL_GREGORIAN, $month, $year));
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

            // Boundary check
            if ($sign === 1 && $maxFeeRow && $date > $maxFeeRow) break;
            if ($sign === -1 && $minFeeRow && $date < $minFeeRow) break;

            // Poplatek za tento měsíc (mirror FeePolicy::regularFee):
            // individuální per měsíc, jinak předpočítaný tarif / základní.
            // + příplatky (služby, placená místa) — strhávají se taky (type 6), D4.
            $individual = $feePolicy->individualFee($memberId, $date);
            $fee = $billed
                ? ($individual ?? $tarifFee ?? $defaultFee ?? 0.0)
                    + $feePolicy->additionalServicesTotal($memberId, $date) + $placesFee
                : 0.0;

            // Add debt payment if any
            if (isset($payments[$year][$month])) {
                $fee += $payments[$year][$month];
            }

            if ($fee > 0) $charged = true;
            $balance -= $sign * $fee;

            if ($balance * $sign < 0) {
                break;
            }

            // Advance month
            $month += $sign;
            if ($month === 0) { $month = 12; $year--; }
            elseif ($month === 13) { $month = 1; $year++; }
        }

        // V žádném měsíci se nic nestrhává (bez poplatku, bývalý člen, osvobozený)
        // → „zaplaceno do" nedává smysl. Dřív cyklus doběhl na limit / hranici
        // ceníku a vracel datum desítky let dopředu (31.08.2076 u bývalého
        // zákazníka 6235 na .54).
        if (!$charged) {
            return null;
        }

        // Back up one month (Kohana: $month-- after break)
        $month -= $sign;
        if ($month === 0) { $month = 12; $year--; }
        elseif ($month === 13) { $month = 1; $year++; }

        // Return last day of that month (Kohana: date::days_of_month($month))
        $day = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** Returns closest deduct date [month, year] from given date */
    private function closestDeductDate(\Carbon\Carbon $date, int $deductDay): array
    {
        $day   = $date->day;
        $month = $date->month;
        $year  = $date->year;

        $dd = min($deductDay, cal_days_in_month(CAL_GREGORIAN, $month, $year));

        if ($day >= $dd) {
            $month++;
            if ($month === 13) { $month = 1; $year++; }
        }

        return [$month, $year];
    }

    /** Collect monthly debt payments into $payments[$year][$month] array */
    private function collectDebtPayments(array &$payments, int $month, int $year, float $total, float $rate): void
    {
        if ($rate <= 0 || $total <= 0) return;
        $remaining = $total;
        $maxMonths = 1200;
        for ($i = 0; $i < $maxMonths && $remaining > 0; $i++) {
            $payment = min($rate, $remaining);
            $payments[$year][$month] = ($payments[$year][$month] ?? 0) + $payment;
            $remaining -= $payment;
            $month++;
            if ($month === 13) { $month = 1; $year++; }
        }
    }
}
