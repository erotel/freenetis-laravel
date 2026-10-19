<?php

namespace App\Domains\Billing\Queries;

use Illuminate\Support\Facades\DB;

/** Statistiky plateb a poplatků po měsících (YYYY-MM) a cashflow stržených poplatků. */
class PaymentStatsQuery
{
    /** member_fee, entrance_fee, device_fee */
    private const DEDUCT_TYPES = [1, 2, 5];

    /**
     * Přijaté platby od členů v měsících roku = převody z „Přijaté členské
     * příspěvky" (684000) na bankovní účet (221000). Ne všechny kladné
     * převody — ty zahrnují i podvojný protizápis na kredit člena, platby
     * dodavatelům a stržené poplatky.
     * @param string[] $months @return float[]
     */
    public function payments(int $year, array $months): array
    {
        $map = collect(DB::select("
            SELECT SUBSTR(t.datetime,1,7) AS month, ROUND(SUM(t.amount),2) AS total
            FROM transfers t
            JOIN accounts o ON o.id = t.origin_id AND o.account_attribute_id = 684000
            JOIN accounts d ON d.id = t.destination_id AND d.account_attribute_id = 221000
            WHERE YEAR(t.datetime) = ? AND t.amount > 0
            GROUP BY month ORDER BY month
        ", [$year]))->pluck('total', 'month')->toArray();
        return array_map(fn ($m) => (float) ($map[$m] ?? 0), $months);
    }

    /** @param string[] $months @return float[] součet poplatků podle měsíce aktivace */
    public function fees(int $year, array $months): array
    {
        $map = collect(DB::select("
            SELECT SUBSTR(mf.activation_date,1,7) AS month, ROUND(SUM(f.fee),2) AS total
            FROM members_fees mf
            JOIN fees f ON f.id = mf.fee_id
            WHERE YEAR(mf.activation_date) = ?
            GROUP BY month ORDER BY month
        ", [$year]))->pluck('total', 'month')->toArray();
        return array_map(fn ($m) => (float) ($map[$m] ?? 0), $months);
    }

    /** Roky, pro které existují převody (novější první). @return int[] */
    public function years(): array
    {
        return array_map(fn ($r) => (int) $r->y, DB::select("SELECT DISTINCT YEAR(datetime) AS y FROM transfers WHERE datetime IS NOT NULL ORDER BY y DESC"));
    }

    /**
     * Stržené poplatky po měsících podle typu člena (2 zákazník, 90 člen) —
     * z kreditního účtu (transfers.member_id u srážky bývá NULL, proto přes účet).
     * @param string[] $months vzestupně
     * @return array<int, array{month: string, deducted: array{2: float, 90: float}}>
     */
    public function deductedByMemberType(array $months): array
    {
        if (empty($months)) {
            return [];
        }
        $from = $months[0] . '-01';
        $to   = date('Y-m-01', strtotime(end($months) . '-01 +1 month'));   // exkluzivně

        $types = implode(',', array_map('intval', self::DEDUCT_TYPES));
        $deducted = [];
        foreach (DB::select("
            SELECT SUBSTR(t.datetime,1,7) AS month, m.type AS mtype, ROUND(SUM(t.amount), 2) AS total
            FROM transfers t
            JOIN accounts a ON a.id = t.origin_id AND a.account_attribute_id = 221100
            JOIN members m ON m.id = a.member_id
            WHERE t.type IN ($types) AND t.datetime >= ? AND t.datetime < ? AND m.type IN (2, 90)
            GROUP BY month, mtype
        ", [$from, $to]) as $r) {
            $deducted[$r->month][(int) $r->mtype] = (float) $r->total;
        }

        return array_map(fn ($m) => ['month' => $m, 'deducted' => [2 => $deducted[$m][2] ?? 0.0, 90 => $deducted[$m][90] ?? 0.0]], $months);
    }
}
