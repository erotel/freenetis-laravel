<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Account extends Model
{
    const ACCOUNTING_SYSTEM = 1;
    const CREDIT            = 2;
    const PROJECT           = 3;
    const OTHER             = 4;

    public $timestamps = false;
    protected $table = 'accounts';
    protected $fillable = ['member_id', 'name', 'account_attribute_id', 'balance', 'comment'];
    protected $casts = ['balance' => 'float'];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function accountAttribute()
    {
        return $this->belongsTo(AccountAttribute::class);
    }

    public function transfers()
    {
        return $this->hasMany(Transfer::class);
    }

    public function variableSymbols()
    {
        return $this->hasMany(VariableSymbol::class);
    }

    public function __toString(): string
    {
        return $this->name ?? ('Účet #' . $this->id);
    }

    /**
     * Calculate "paid until" date — port of Kohana Members_Controller::get_expiration_date().
     * Returns date string (Y-m-d) or null if cannot be determined.
     */
    public function getExpirationDate(): ?string
    {
        $balance = (float) $this->balance;
        $memberId = $this->member_id;

        // Deduct day from settings (default 26)
        $deductDay = (int) Setting::get('deduct_day', 26);

        // Last DEDUCT transfer (fee deductions only, origin = this account)
        // Types: 1=DEDUCT_MEMBER_FEE, 2=DEDUCT_ENTRANCE_FEE, 5=DEDUCT_DEVICE_FEE
        $lastTransfer = DB::table('transfers')
            ->where('origin_id', $this->id)
            ->whereIn('type', [1, 2, 5])
            ->max('datetime');

        // Start date: last transfer or member entrance date
        $member = $this->member;
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
            $devices = DB::table('devices')
                ->where('user_id', function ($q) use ($memberId) {
                    $q->select('id')->from('users')->where('member_id', $memberId)->limit(1);
                })
                ->whereNotNull('buy_date')
                ->where('price', '>', 0)
                ->where('payment_rate', '>', 0)
                ->get(['buy_date', 'price', 'payment_rate']);

            foreach ($devices as $device) {
                $buyDate = \Carbon\Carbon::parse($device->buy_date);
                [$dm, $dy] = $this->closestDeductDate($buyDate, $deductDay);
                $this->collectDebtPayments($payments, $dm, $dy, (float)$device->price, (float)$device->payment_rate);
            }
        }

        // Fee date bounds
        $minFeeRow = DB::table('fees')
            ->join('enum_types', 'enum_types.id', '=', 'fees.type_id')
            ->where('enum_types.value', 'regular member fee')
            ->min('fees.from');
        $maxFeeRow = DB::table('fees')
            ->join('enum_types', 'enum_types.id', '=', 'fees.type_id')
            ->where('enum_types.value', 'regular member fee')
            ->max('fees.to');

        $maxIterations = 600; // ~50 years

        for ($i = 0; $i < $maxIterations; $i++) {
            $day = min($deductDay, cal_days_in_month(CAL_GREGORIAN, $month, $year));
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

            // Boundary check
            if ($sign === 1 && $maxFeeRow && $date > $maxFeeRow) break;
            if ($sign === -1 && $minFeeRow && $date < $minFeeRow) break;

            // Get regular member fee for this month
            $fee = $this->regularFeeForMember($memberId, $date);

            // Add debt payment if any
            if (isset($payments[$year][$month])) {
                $fee += $payments[$year][$month];
            }

            $balance -= $sign * $fee;

            if ($balance * $sign < 0) {
                break;
            }

            // Advance month
            $month += $sign;
            if ($month === 0) { $month = 12; $year--; }
            elseif ($month === 13) { $month = 1; $year++; }
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

    /** Get regular member fee for member on date — member-specific first, then association default */
    private function regularFeeForMember(int $memberId, string $date): float
    {
        $row = DB::table('members_fees as mf')
            ->join('fees as f', 'f.id', '=', 'mf.fee_id')
            ->join('enum_types as et', 'et.id', '=', 'f.type_id')
            ->where('mf.member_id', $memberId)
            ->where('et.value', 'regular member fee')
            ->where('mf.activation_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('mf.deactivation_date')
                  ->orWhere('mf.deactivation_date', '>=', $date);
            })
            ->orderBy('mf.priority')
            ->first(['f.fee']);

        if ($row) return (float) $row->fee;

        // Fallback: association default (member_id = 1)
        $default = DB::table('members_fees as mf')
            ->join('fees as f', 'f.id', '=', 'mf.fee_id')
            ->join('enum_types as et', 'et.id', '=', 'f.type_id')
            ->where('mf.member_id', 1)
            ->where('et.value', 'regular member fee')
            ->where('mf.activation_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('mf.deactivation_date')
                  ->orWhere('mf.deactivation_date', '>=', $date);
            })
            ->orderBy('mf.priority')
            ->first(['f.fee']);

        return $default ? (float) $default->fee : 0.0;
    }
}
