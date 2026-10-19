<?php

namespace App\Domains\Members\Queries;

use Illuminate\Support\Facades\DB;

/** Statistika členství po měsících (YYYY-MM): přírůstek, úbytek, počet aktivních. */
class MembershipStatsQuery
{
    /** @param string[] $months @return int[] počet vstupů v měsíci (za posledních 24 měsíců) */
    public function joined(array $months): array
    {
        $map = collect(DB::select("
            SELECT SUBSTR(entrance_date,1,7) AS month, COUNT(*) AS cnt
            FROM members
            WHERE entrance_date > DATE_SUB(NOW(), INTERVAL 24 MONTH)
            GROUP BY month ORDER BY month
        "))->pluck('cnt', 'month')->toArray();
        return array_map(fn ($m) => (int) ($map[$m] ?? 0), $months);
    }

    /** @param string[] $months @return int[] počet odchodů v měsíci */
    public function left(array $months): array
    {
        $map = collect(DB::select("
            SELECT SUBSTR(leaving_date,1,7) AS month, COUNT(*) AS cnt
            FROM members
            WHERE leaving_date IS NOT NULL
              AND leaving_date != '9999-12-31'
              AND leaving_date != '0000-00-00'
              AND leaving_date > DATE_SUB(NOW(), INTERVAL 24 MONTH)
            GROUP BY month ORDER BY month
        "))->pluck('cnt', 'month')->toArray();
        return array_map(fn ($m) => (int) ($map[$m] ?? 0), $months);
    }

    /** @param string[] $months @return int[] členové aktivní v daném měsíci */
    public function active(array $months): array
    {
        return array_map(fn ($m) => DB::table('members')
            ->where('entrance_date', '<=', $m . '-31')
            ->where(function ($q) use ($m) {
                $q->where('leaving_date', '>=', $m . '-01')->orWhere('leaving_date', '9999-12-31')->orWhereNull('leaving_date');
            })
            ->count(), $months);
    }
}
