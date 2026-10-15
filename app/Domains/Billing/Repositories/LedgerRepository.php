<?php

namespace App\Domains\Billing\Repositories;

use App\Domains\Billing\Support\AccountAttribute;
use App\Domains\Billing\Support\TransferType;
use App\Helpers\MemberType;
use Illuminate\Support\Facades\DB;

/**
 * Účetnictví členů nad legacy tabulkami `accounts` / `transfers` / `members`
 * (podvojné: převod = origin → destination, zůstatek účtu = Σ příchozí − Σ odchozí).
 *
 * Dotazy „kdo má zaplatit" jsou hromadné SQL nad všemi členy (výkon). Idempotence
 * srážek = LEFT JOIN / NOT IN na převod stejného typu s `datetime` = den srážky.
 * Pravidla výše poplatku zrcadlí App\Services\RegularFeeResolver a
 * AllowedSubnetFeesResolver — změna pravidla musí jít do všech.
 */
class LedgerRepository
{
    /** Účet organizace (member 1) daného druhu. */
    public function organizationAccountId(int $attribute): ?int
    {
        $id = DB::table('accounts')
            ->where('member_id', 1)
            ->where('account_attribute_id', $attribute)
            ->value('id');
        return $id ? (int) $id : null;
    }

    public function feeAmount(int $feeId): float
    {
        return (float) DB::table('fees')->where('id', $feeId)->value('fee');
    }

    // ── kdo má k datu zaplatit (bez převodu daného typu k témuž dni) ──────────

    /**
     * Kreditní účty aktivních členů pro členský poplatek: individuální poplatek
     * (members_fees 'regular member fee', nejnižší priority) + cena tarifu.
     * Vstup PŘED datem, odchod po datu (nebo neurčen). Bývalí (15/16) nikdy —
     * ve výpovědní lhůtě má člen ještě svůj typ, bývalý typ = už odešel.
     */
    public function dueMemberFees(string $date, ?int $only = null): array
    {
        $mf = $only ? " AND m.id = {$only}" : '';
    return DB::select("
                SELECT a.id AS account_id, a.balance, m.id AS member_id, m.type AS member_type,
                    sc.price AS tarif_fee,
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
                LEFT JOIN speed_classes sc ON sc.id = m.speed_class_id
                LEFT JOIN transfers t ON t.origin_id = a.id
                    AND t.type = :type AND t.datetime = :date3
                WHERE m.id <> 1{$mf}
                  AND a.account_attribute_id = :credit
                  AND m.type NOT IN (15, 16)
                  AND m.entrance_date < :date4
                  AND (m.leaving_date = '0000-00-00' OR m.leaving_date = '9999-12-31' OR m.leaving_date > :date5)
                  AND t.id IS NULL
            ", [
                'date1'  => $date,
                'date2'  => $date,
                'type'   => TransferType::MEMBER_FEE,
                'date3'  => $date,
                'credit' => AccountAttribute::CREDIT,
                'date4'  => $date,
                'date5'  => $date,
            ]);
    }

    /**
     * Součet dodatečných služeb (members_fees 'additional service') + placených
     * přípojných míst (allowed_subnets.charged, cena vlastní rychlosti místa).
     */
    public function dueAdditionalServiceFees(string $date, ?int $only = null): array
    {
        $mf = $only ? " AND m.id = {$only}" : '';
    return DB::select("
                SELECT a.id AS account_id, a.balance, m.id AS member_id,
                    (
                        (
                            SELECT COALESCE(SUM(f2.fee), 0)
                            FROM members_fees mf2
                            JOIN fees f2 ON f2.id = mf2.fee_id
                            JOIN enum_types et2 ON et2.id = f2.type_id
                            WHERE LOWER(et2.value) = 'additional service'
                              AND mf2.member_id = m.id
                              AND mf2.activation_date <= :date1
                              AND mf2.deactivation_date >= :date2
                        )
                        +
                        (
                            -- Placená přípojná místa (mirror AllowedSubnetFeesResolver):
                            -- cena = vlastní rychlost místa; zděděná rychlost se neúčtuje.
                            SELECT COALESCE(SUM(COALESCE(scp.price, 0)), 0)
                            FROM allowed_subnets asub
                            JOIN speed_classes scp ON scp.id = asub.speed_class_id
                            WHERE asub.member_id = m.id
                              AND asub.charged = 1
                        )
                    ) AS svc_fee
                FROM accounts a
                JOIN members m ON a.member_id = m.id
                LEFT JOIN transfers t ON t.origin_id = a.id
                    AND t.type = :type AND t.datetime = :date3
                WHERE m.id <> 1{$mf}
                  AND a.account_attribute_id = :credit
                  AND m.type NOT IN (15, 16)
                  AND m.entrance_date < :date4
                  AND (m.leaving_date = '0000-00-00' OR m.leaving_date = '9999-12-31' OR m.leaving_date > :date5)
                  AND t.id IS NULL
            ", [
                'date1'  => $date,
                'date2'  => $date,
                'type'   => TransferType::ADDITIONAL_SERVICE_FEE,
                'date3'  => $date,
                'credit' => AccountAttribute::CREDIT,
                'date4'  => $date,
                'date5'  => $date,
            ]);
    }

    /**
     * Splátka vstupního poplatku: dluh = entrance_fee − Σ zaplacených (type 2),
     * splátka = min(dluh, debt_payment_rate). Bez čekatelů, uchazečů a bývalých.
     */
    public function dueEntranceFees(string $date, ?int $only = null): array
    {
        $mf = $only ? " AND m.id = {$only}" : '';
    return DB::select("
                SELECT a.id AS account_id, ac.member_id, ac.balance,
                    IF(debt > debt_payment_rate, debt_payment_rate, debt) AS amount
                FROM (
                    SELECT a.id, MIN(m.debt_payment_rate) AS debt_payment_rate,
                        IFNULL(MIN(m.entrance_fee), 0) - IFNULL(SUM(t.amount), 0) AS debt
                    FROM accounts a
                    JOIN members m ON a.member_id = m.id
                    LEFT JOIN transfers t ON t.origin_id = a.id AND t.type = :type1
                    WHERE a.account_attribute_id = :credit{$mf}
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
                'type1'      => TransferType::ENTRANCE_FEE,
                'credit'     => AccountAttribute::CREDIT,
                'applicant'        => MemberType::APPLICANT,
                'former_customer'  => MemberType::FORMER_CUSTOMER,
                'pending_member'   => MemberType::PENDING_MEMBER,
                'pending_customer' => MemberType::PENDING_CUSTOMER,
                'date1'      => $date,
                'former'     => MemberType::FORMER,
                'date2'      => $date,
                'type2'      => TransferType::ENTRANCE_FEE,
                'date3'      => $date,
            ]);
    }

    /**
     * Splátka zařízení: dluh = devices.price − Σ zaplacených (type 5),
     * splátka = min(dluh, payment_rate). Bez filtru typu člena / odchodu.
     */
    public function dueDeviceFees(string $date, ?int $only = null): array
    {
        $mf = $only ? " AND m.id = {$only}" : '';
    return DB::select("
                SELECT a.id AS account_id, ac.member_id, ac.balance,
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
                    ){$mf}
                    GROUP BY a.id
                ) a
                JOIN accounts ac ON ac.id = a.id
                WHERE a.debt > 0
            ", [
                'type1'  => TransferType::DEVICE_FEE,
                'type2'  => TransferType::DEVICE_FEE,
                'date1'  => $date,
            ]);
    }

    // ── zápisy ────────────────────────────────────────────────────────────────

    /** Převod z účtu člena na účet organizace + snížení zůstatku člena. */
    public function postFee(int $fromAccount, int $toAccount, int $type, float $amount, string $date, string $createdAt, string $text): void
    {
        DB::table('transfers')->insert([
            'origin_id'         => $fromAccount,
            'destination_id'    => $toAccount,
            'type'              => $type,
            'amount'            => $amount,
            'datetime'          => $date,
            'creation_datetime' => $createdAt,
            'text'              => $text,
            'member_id'         => null,
            'user_id'           => null,
        ]);

        DB::table('accounts')->where('id', $fromAccount)->decrement('balance', $amount);
    }

    /**
     * Prepaid blokace (přesměrování na výzvu k platbě). Datum vzniku dluhu se
     * nepřepisuje — drží se původní (auto-ukončení počítá od něj).
     */
    public function blockForPayment(int $memberId, string $date): void
    {
        DB::table('members')
            ->where('id', $memberId)
            ->update([
                'payment_blocked'       => 1,
                'payment_blocked_since' => DB::raw("COALESCE(payment_blocked_since, " . DB::getPdo()->quote($date) . ")"),
            ]);
    }

    /** Zruší blokaci, pokud byla nastavená. @return bool true = byla zrušena */
    public function unblockIfBlocked(int $memberId): bool
    {
        return DB::table('members')
            ->where('id', $memberId)
            ->where('payment_blocked', 1)
            ->update(['payment_blocked' => 0, 'payment_blocked_since' => null]) > 0;
    }

    /**
     * Přepočet zůstatku účtu ze všech převodů (Kohana recalculate_account_balance_of_account).
     * Balance = SUM(incoming) - SUM(outgoing)
     */
    public function recalculateBalance(int $accountId): void
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
