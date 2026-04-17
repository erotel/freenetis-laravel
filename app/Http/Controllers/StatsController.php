<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
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
}
