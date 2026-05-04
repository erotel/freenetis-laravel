<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    private const DEDUCT_TYPES = [1, 2, 5];    // member_fee, entrance_fee, device_fee

    public function index()
    {
        if (!$this->aclCheck('view_all', 'Stats_Controller', 'members_growth')) {
            abort(403);
        }

        $months24 = $this->last24Months();

        // ── 1. Přírůstek / úbytek po měsících ────────────────────────────────
        $incRows = DB::select("
            SELECT SUBSTR(entrance_date,1,7) AS month, COUNT(*) AS cnt
            FROM members
            WHERE entrance_date > DATE_SUB(NOW(), INTERVAL 24 MONTH)
            GROUP BY month ORDER BY month
        ");
        $decRows = DB::select("
            SELECT SUBSTR(leaving_date,1,7) AS month, COUNT(*) AS cnt
            FROM members
            WHERE leaving_date IS NOT NULL
              AND leaving_date != '9999-12-31'
              AND leaving_date != '0000-00-00'
              AND leaving_date > DATE_SUB(NOW(), INTERVAL 24 MONTH)
            GROUP BY month ORDER BY month
        ");
        $incMap = collect($incRows)->pluck('cnt', 'month')->toArray();
        $decMap = collect($decRows)->pluck('cnt', 'month')->toArray();
        $incrData   = array_map(fn($m) => (int)($incMap[$m] ?? 0), $months24);
        $decrData   = array_map(fn($m) => (int)($decMap[$m] ?? 0), $months24);

        // ── 2. Kumulativní počet aktivních členů ──────────────────────────────
        $growthData = [];
        foreach ($months24 as $m) {
            $end   = $m . '-31';
            $start = $m . '-01';
            $cnt = DB::table('members')
                ->where('entrance_date', '<=', $end)
                ->where(function ($q) use ($start) {
                    $q->where('leaving_date', '>=', $start)
                      ->orWhere('leaving_date', '9999-12-31')
                      ->orWhereNull('leaving_date');
                })
                ->count();
            $growthData[] = $cnt;
        }

        // ── 3. Přijaté platby za aktuální rok ────────────────────────────────
        $year = now()->year;
        $payRows = DB::select("
            SELECT SUBSTR(datetime,1,7) AS month, ROUND(SUM(amount),2) AS total
            FROM transfers
            WHERE YEAR(datetime) = ? AND amount > 0
            GROUP BY month ORDER BY month
        ", [$year]);
        $payMonths = $this->monthsOfYear($year);
        $payMap    = collect($payRows)->pluck('total', 'month')->toArray();
        $payData   = array_map(fn($m) => (float)($payMap[$m] ?? 0), $payMonths);

        // ── 4. Poplatky členů za aktuální rok ────────────────────────────────
        $feeRows = DB::select("
            SELECT SUBSTR(mf.activation_date,1,7) AS month, ROUND(SUM(f.fee),2) AS total
            FROM members_fees mf
            JOIN fees f ON f.id = mf.fee_id
            WHERE YEAR(mf.activation_date) = ?
            GROUP BY month ORDER BY month
        ", [$year]);
        $feeMap  = collect($feeRows)->pluck('total', 'month')->toArray();
        $feeData = array_map(fn($m) => (float)($feeMap[$m] ?? 0), $payMonths);

        return view('stats.index', [
            'months24'   => $months24,
            'incrData'   => $incrData,
            'decrData'   => $decrData,
            'growthData' => $growthData,
            'payMonths'  => $payMonths,
            'payData'    => $payData,
            'feeData'    => $feeData,
            'year'       => $year,
        ]);
    }

    private function last24Months(): array
    {
        $months = [];
        for ($i = 23; $i >= 0; $i--) {
            $months[] = now()->subMonths($i)->format('Y-m');
        }
        return $months;
    }

    private function monthsOfYear(int $year): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = sprintf('%d-%02d', $year, $m);
        }
        return $months;
    }

    public function cashflow(Request $request)
    {
        if (!$this->aclCheck('view_all', 'Stats_Controller', 'members_growth')) {
            abort(403);
        }

        // ── Rozsah měsíců ─────────────────────────────────────────────────────
        // ?year=2025 → leden..prosinec 2025
        // bez parametru → posledních 12 měsíců (rolling)
        $year = (int) $request->query('year', 0);
        if ($year >= 2000 && $year <= 2100) {
            $months = $this->monthsOfYear($year);
        } else {
            $year   = 0;
            $months = $this->lastNMonths(12);
        }

        // ── Roky pro dropdown ─────────────────────────────────────────────────
        $availableYears = $this->availableYears();

        // ── Spočítat data (s 10min cache) ─────────────────────────────────────
        $cacheKey = 'stats.cashflow.' . ($year ?: 'rolling12');
        $rows = Cache::remember($cacheKey, 600, fn() => $this->computeCashflowRows($months));

        // Nejnovější nahoře
        $rows = array_reverse($rows);

        return view('stats.cashflow', [
            'rows'           => $rows,
            'year'           => $year,
            'availableYears' => $availableYears,
        ]);
    }

    /**
     * Posledních N měsíců včetně aktuálního, vzestupně (od nejstaršího po nejnovější).
     */
    private function lastNMonths(int $n): array
    {
        $months = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $months[] = now()->subMonths($i)->format('Y-m');
        }
        return $months;
    }

    /**
     * Roky pro které existují transfery (DESC, novější první).
     */
    private function availableYears(): array
    {
        $rows = DB::select("SELECT DISTINCT YEAR(datetime) AS y FROM transfers WHERE datetime IS NOT NULL ORDER BY y DESC");
        return array_map(fn($r) => (int) $r->y, $rows);
    }

    /**
     * Vrátí pole řádků pro tabulku, jeden řádek na měsíc:
     *   ['month' => 'YYYY-MM', 'deducted' => [2 => float, 90 => float]]
     */
    private function computeCashflowRows(array $months): array
    {
        if (empty($months)) return [];

        $monthMin = $months[0] . '-01';
        $monthMaxStart = end($months);
        // Konec rozsahu = první den následujícího měsíce (exkluzivně)
        $monthMaxExclusive = date('Y-m-01', strtotime($monthMaxStart . '-01 +1 month'));

        // ── Strženo členům, split podle members.type (2, 90) ─────────────────
        // Pozn: transfers.member_id z DeductFees je NULL, takže join na members
        // jdeme přes origin_id → accounts (credit, attr=221100) → member.
        $deductTypesIn = implode(',', array_map('intval', self::DEDUCT_TYPES));
        $deductRows = DB::select("
            SELECT SUBSTR(t.datetime,1,7) AS month, m.type AS mtype,
                   ROUND(SUM(t.amount), 2) AS total
            FROM transfers t
            JOIN accounts a ON a.id = t.origin_id AND a.account_attribute_id = 221100
            JOIN members m ON m.id = a.member_id
            WHERE t.type IN ($deductTypesIn)
              AND t.datetime >= ? AND t.datetime < ?
              AND m.type IN (2, 90)
            GROUP BY month, mtype
        ", [$monthMin, $monthMaxExclusive]);

        $deducted = []; // ['2026-04' => [2 => 1234, 90 => 5678]]
        foreach ($deductRows as $r) {
            $deducted[$r->month][(int) $r->mtype] = (float) $r->total;
        }

        // ── Sestavit řádky pro view ──────────────────────────────────────────
        $out = [];
        foreach ($months as $m) {
            $out[] = [
                'month'    => $m,
                'deducted' => [
                    2  => $deducted[$m][2]  ?? 0.0,
                    90 => $deducted[$m][90] ?? 0.0,
                ],
            ];
        }
        return $out;
    }
}
