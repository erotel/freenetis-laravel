<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Queries\PaymentStatsQuery;
use App\Domains\Members\Queries\MembershipStatsQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Statistiky členství, plateb a cashflow; dotazy v doménách Members a Billing. */
class StatsController extends Controller
{
    public function index(MembershipStatsQuery $membership, PaymentStatsQuery $payments)
    {
        if (!$this->aclCheck('view_all', 'Stats_Controller', 'members_growth')) {
            abort(403);
        }

        $months24  = self::lastMonths(24);
        $year      = now()->year;
        $payMonths = self::monthsOfYear($year);

        return view('stats.index', [
            'months24'   => $months24,
            'incrData'   => $membership->joined($months24),
            'decrData'   => $membership->left($months24),
            'growthData' => $membership->active($months24),
            'payMonths'  => $payMonths,
            'payData'    => $payments->payments($year, $payMonths),
            'feeData'    => $payments->fees($year, $payMonths),
            'year'       => $year,
        ]);
    }

    /** ?year=2025 → leden..prosinec; bez něj posledních 12 měsíců. Data v cache 10 min. */
    public function cashflow(Request $request, PaymentStatsQuery $payments)
    {
        if (!$this->aclCheck('view_all', 'Stats_Controller', 'members_growth')) {
            abort(403);
        }

        $year = (int) $request->query('year', 0);
        if ($year >= 2000 && $year <= 2100) {
            $months = self::monthsOfYear($year);
        } else {
            $year   = 0;
            $months = self::lastMonths(12);
        }

        $rows = Cache::remember('stats.cashflow.' . ($year ?: 'rolling12'), 600, fn () => $payments->deductedByMemberType($months));

        return view('stats.cashflow', [
            'rows'           => array_reverse($rows),   // nejnovější nahoře
            'year'           => $year,
            'availableYears' => $payments->years(),
        ]);
    }

    /** Posledních N měsíců včetně aktuálního, vzestupně. */
    private static function lastMonths(int $n): array
    {
        $months = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $months[] = now()->subMonths($i)->format('Y-m');
        }
        return $months;
    }

    private static function monthsOfYear(int $year): array
    {
        return array_map(fn ($m) => sprintf('%d-%02d', $year, $m), range(1, 12));
    }
}
