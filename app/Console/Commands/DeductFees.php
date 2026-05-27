<?php
namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeductFees extends Command
{
    protected $signature   = 'fees:deduct {--date= : Date YYYY-MM-DD, defaults to today} {--force : Skip enabled/day checks}';
    protected $description = 'Monthly fee deduction: member fees, entrance fees, device fees';

    // Transfer types (matches Kohana Transfer_Model constants)
    const TYPE_MEMBER_FEE   = 1;
    const TYPE_ENTRANCE_FEE = 2;
    const TYPE_DEVICE_FEE   = 5;

    // Account attribute IDs
    const CREDIT_ACCOUNT         = 221100;
    const OPERATING_ACCOUNT      = 221101;
    const INFRASTRUCTURE_ACCOUNT = 221102;

    // Member type IDs (matches Kohana Member_Model constants)
    const MEMBER_TYPE_APPLICANT = 1;
    const MEMBER_TYPE_FORMER          = 15;
    const MEMBER_TYPE_FORMER_CUSTOMER  = 16;
    const MEMBER_TYPE_PENDING_MEMBER   = 17;
    const MEMBER_TYPE_PENDING_CUSTOMER = 18;

    public function handle(): int
    {
        // Check if finance/deduction enabled
        if (!$this->option('force')) {
            if (!Setting::get('finance_enabled', 0)) {
                $this->info('Finance disabled, skipping.');
                return 0;
            }
            if (!Setting::get('deduct_fees_automatically_enabled', 0)) {
                $this->info('Automatic fee deduction disabled, skipping.');
                return 0;
            }
        }

        // Determine date
        $date = $this->option('date') ?? date('Y-m-d');
        [, , $day] = explode('-', $date);

        // Check deduct_day — run only on configured day (or last day of month if configured day > days in month)
        if (!$this->option('force')) {
            $deductDay    = (int) Setting::get('deduct_day', 26);
            $lastDay      = (int) date('t', strtotime($date));
            $effectiveDay = min($deductDay, $lastDay);
            if ((int)$day !== $effectiveDay) {
                $this->info("Today is day {$day}, deduct_day is {$effectiveDay} — skipping.");
                return 0;
            }
        }

        $this->info("Running fee deduction for date {$date}...");

        $orgOperating = DB::table('accounts')
            ->where('member_id', 1)
            ->where('account_attribute_id', self::OPERATING_ACCOUNT)
            ->value('id');

        $orgInfrastructure = DB::table('accounts')
            ->where('member_id', 1)
            ->where('account_attribute_id', self::INFRASTRUCTURE_ACCOUNT)
            ->value('id');

        if (!$orgOperating || !$orgInfrastructure) {
            $this->error('Organization operating/infrastructure account not found!');
            return 1;
        }

        $deducted = 0;
        $deducted += $this->deductMemberFees($date, $orgOperating);
        $deducted += $this->deductEntranceFees($date, $orgInfrastructure);
        $deducted += $this->deductDeviceFees($date, $orgOperating);

        $this->info("Done. Total deductions: {$deducted}");
        return 0;
    }

    private function deductMemberFees(string $date, int $orgAccount): int
    {
        // Výchozí poplatek dle nastavení (Settings → Finance), fallback na fees tabulku
        $defaultFeeIdType2  = (int) Setting::get('default_fee_member_type_2', 0);
        $defaultFeeIdType90 = (int) Setting::get('default_fee_member_type_90', 0);

        $defaultFeeType2  = $defaultFeeIdType2
            ? (float) DB::table('fees')->where('id', $defaultFeeIdType2)->value('fee')
            : null;
        $defaultFeeType90 = $defaultFeeIdType90
            ? (float) DB::table('fees')->where('id', $defaultFeeIdType90)->value('fee')
            : null;

        if ($defaultFeeType2 === null && $defaultFeeType90 === null) {
            $this->warn('Výchozí poplatky nejsou nastaveny v Nastavení → Finance. Používám fallback z tabulky fees.');
        }

        // Members_fees per-member override: highest-priority active fee of type 'regular member fee'
        // Idempotency: LEFT JOIN transfers where type=1 and datetime=$date (exact)
        $accounts = DB::select("
            SELECT a.id AS account_id, a.balance, m.id AS member_id, m.type AS member_type,
                (
                    SELECT f2.fee
                    FROM members_fees mf2
                    JOIN fees f2 ON f2.id = mf2.fee_id
                    JOIN enum_types et2 ON et2.id = f2.type_id
                    WHERE LOWER(et2.value) = 'regular member fee'
                      AND mf2.member_id = m.id
                      AND mf2.activation_date <= :date1
                      AND mf2.deactivation_date >= :date2
                    ORDER BY mf2.priority
                    LIMIT 1
                ) AS individual_fee
            FROM accounts a
            JOIN members m ON a.member_id = m.id
            LEFT JOIN transfers t ON t.origin_id = a.id
                AND t.type = :type AND t.datetime = :date3
            WHERE m.id <> 1
              AND a.account_attribute_id = :credit
              AND m.entrance_date < :date4
              AND (m.leaving_date = '0000-00-00' OR m.leaving_date = '9999-12-31' OR m.leaving_date > :date5)
              AND t.id IS NULL
        ", [
            'date1'  => $date,
            'date2'  => $date,
            'type'   => self::TYPE_MEMBER_FEE,
            'date3'  => $date,
            'credit' => self::CREDIT_ACCOUNT,
            'date4'  => $date,
            'date5'  => $date,
        ]);

        $count = 0;
        $creationDatetime = date('Y-m-d H:i:s');

        DB::beginTransaction();
        try {
            foreach ($accounts as $account) {
                // Individuální tarif (members_fees) má přednost — i 0 Kč = osvobozený člen
                // (feeAmount 0 se níže přeskočí). Bez přiřazeného tarifu => výchozí poplatek dle typu.
                if ($account->individual_fee !== null) {
                    $feeAmount = (float)$account->individual_fee;
                } else {
                    $feeAmount = match((int)$account->member_type) {
                        2  => $defaultFeeType2  ?? 0.0,
                        90 => $defaultFeeType90 ?? 0.0,
                        default => 0.0,
                    };
                }
                if ($feeAmount <= 0) continue;

                DB::table('transfers')->insert([
                    'origin_id'         => $account->account_id,
                    'destination_id'    => $orgAccount,
                    'type'              => self::TYPE_MEMBER_FEE,
                    'amount'            => $feeAmount,
                    'datetime'          => $date,
                    'creation_datetime' => $creationDatetime,
                    'text'              => 'Automatická srážka členského příspěvku',
                    'member_id'         => null,
                    'user_id'           => null,
                ]);

                DB::table('accounts')->where('id', $account->account_id)->decrement('balance', $feeAmount);
                $count++;
            }

            // Recalculate operating account balance from all transfers
            if ($count > 0) {
                $this->recalculateBalance($orgAccount);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('DeductFees member fee error', ['error' => $e->getMessage()]);
            $this->error('Member fee deduction failed: ' . $e->getMessage());
        }

        $this->info("Member fees: {$count} deductions.");
        return $count;
    }

    private function deductEntranceFees(string $date, int $orgAccount): int
    {
        // debt = entrance_fee - SUM(already paid type=2 transfers)
        // rate = members.debt_payment_rate (per-member column)
        // exclude: applicants (type=1), former members (type=15) whose leaving_date has passed
        // idempotency: exclude accounts that already have a type=2 transfer with datetime=$date
        $accounts = DB::select("
            SELECT a.id AS account_id, ac.member_id,
                IF(debt > debt_payment_rate, debt_payment_rate, debt) AS amount
            FROM (
                SELECT a.id, MIN(m.debt_payment_rate) AS debt_payment_rate,
                    IFNULL(MIN(m.entrance_fee), 0) - IFNULL(SUM(t.amount), 0) AS debt
                FROM accounts a
                JOIN members m ON a.member_id = m.id
                LEFT JOIN transfers t ON t.origin_id = a.id AND t.type = :type1
                WHERE a.account_attribute_id = :credit
                  AND m.entrance_fee > 0
                  AND m.type <> :applicant
                  AND m.type <> :former_customer
                  AND m.type <> :pending_member
                  AND m.type <> :pending_customer
                  AND m.entrance_date < :date1
                  AND (m.type <> :former OR m.leaving_date > :date2)
                  AND a.id NOT IN (
                      SELECT t2.origin_id FROM transfers t2
                      WHERE t2.type = :type2 AND t2.datetime = :date3
                  )
                GROUP BY a.id
            ) a
            JOIN accounts ac ON ac.id = a.id
            WHERE a.debt > 0
        ", [
            'type1'      => self::TYPE_ENTRANCE_FEE,
            'credit'     => self::CREDIT_ACCOUNT,
            'applicant'        => self::MEMBER_TYPE_APPLICANT,
            'former_customer'  => self::MEMBER_TYPE_FORMER_CUSTOMER,
            'pending_member'   => self::MEMBER_TYPE_PENDING_MEMBER,
            'pending_customer' => self::MEMBER_TYPE_PENDING_CUSTOMER,
            'date1'      => $date,
            'former'     => self::MEMBER_TYPE_FORMER,
            'date2'      => $date,
            'type2'      => self::TYPE_ENTRANCE_FEE,
            'date3'      => $date,
        ]);

        $count = 0;
        $creationDatetime = date('Y-m-d H:i:s');

        DB::beginTransaction();
        try {
            foreach ($accounts as $account) {
                $amount = (float)$account->amount;
                if ($amount <= 0) continue;

                DB::table('transfers')->insert([
                    'origin_id'         => $account->account_id,
                    'destination_id'    => $orgAccount,
                    'type'              => self::TYPE_ENTRANCE_FEE,
                    'amount'            => $amount,
                    'datetime'          => $date,
                    'creation_datetime' => $creationDatetime,
                    'text'              => 'Automatická srážka vstupního poplatku',
                    'member_id'         => null,
                    'user_id'           => null,
                ]);

                DB::table('accounts')->where('id', $account->account_id)->decrement('balance', $amount);
                $count++;
            }

            if ($count > 0) {
                $this->recalculateBalance($orgAccount);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('DeductFees entrance fee error', ['error' => $e->getMessage()]);
            $this->error('Entrance fee deduction failed: ' . $e->getMessage());
        }

        $this->info("Entrance fees: {$count} deductions.");
        return $count;
    }

    private function deductDeviceFees(string $date, int $orgAccount): int
    {
        // debt = device.price - SUM(already paid type=5 transfers)
        // rate = devices.payment_rate
        // idempotency: exclude accounts that already have a type=5 transfer with datetime=$date
        $accounts = DB::select("
            SELECT a.id AS account_id, ac.member_id,
                IF(debt > payment_rate, payment_rate, debt) AS amount
            FROM (
                SELECT a.id, MIN(d.payment_rate) AS payment_rate,
                    IFNULL(MIN(d.price), 0) - IFNULL(SUM(t.amount), 0) AS debt
                FROM accounts a
                JOIN members m ON a.member_id = m.id
                JOIN users u ON u.member_id = m.id
                JOIN devices d ON d.user_id = u.id AND d.price IS NOT NULL AND d.price > 0
                LEFT JOIN transfers t ON t.origin_id = a.id AND t.type = :type1
                WHERE a.id NOT IN (
                    SELECT t2.origin_id FROM transfers t2
                    WHERE t2.type = :type2 AND t2.datetime = :date1
                )
                GROUP BY a.id
            ) a
            JOIN accounts ac ON ac.id = a.id
            WHERE a.debt > 0
        ", [
            'type1'  => self::TYPE_DEVICE_FEE,
            'type2'  => self::TYPE_DEVICE_FEE,
            'date1'  => $date,
        ]);

        $count = 0;
        $creationDatetime = date('Y-m-d H:i:s');

        DB::beginTransaction();
        try {
            foreach ($accounts as $account) {
                $amount = (float)$account->amount;
                if ($amount <= 0) continue;

                DB::table('transfers')->insert([
                    'origin_id'         => $account->account_id,
                    'destination_id'    => $orgAccount,
                    'type'              => self::TYPE_DEVICE_FEE,
                    'amount'            => $amount,
                    'datetime'          => $date,
                    'creation_datetime' => $creationDatetime,
                    'text'              => 'Automatická srážka poplatku za zařízení',
                    'member_id'         => null,
                    'user_id'           => null,
                ]);

                DB::table('accounts')->where('id', $account->account_id)->decrement('balance', $amount);
                $count++;
            }

            if ($count > 0) {
                $this->recalculateBalance($orgAccount);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('DeductFees device fee error', ['error' => $e->getMessage()]);
            $this->error('Device fee deduction failed: ' . $e->getMessage());
        }

        $this->info("Device fees: {$count} deductions.");
        return $count;
    }

    /**
     * Recalculate account balance from all transfers (matching Kohana's recalculate_account_balance_of_account).
     * Balance = SUM(incoming) - SUM(outgoing)
     */
    private function recalculateBalance(int $accountId): void
    {
        $incoming = (float) DB::table('transfers')
            ->where('destination_id', $accountId)
            ->sum('amount');

        $outgoing = (float) DB::table('transfers')
            ->where('origin_id', $accountId)
            ->sum('amount');

        DB::table('accounts')
            ->where('id', $accountId)
            ->update(['balance' => $incoming - $outgoing]);
    }
}
