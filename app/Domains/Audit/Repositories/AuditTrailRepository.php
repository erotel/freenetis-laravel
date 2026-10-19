<?php

namespace App\Domains\Audit\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Auditní stopa (audit_logs) — historie změn záznamů pro detailní stránky (NIS2/ZoKB). */
class AuditTrailRepository
{
    /**
     * Nejnovější změny daných záznamů s jménem a loginem autora.
     *
     * @param array<string, int[]> $records auditable_type => id záznamů
     */
    public function history(array $records, int $limit = 100): Collection
    {
        return DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where(function ($q) use ($records) {
                foreach ($records as $type => $ids) {
                    if (!empty($ids)) {
                        $q->orWhere(fn ($x) => $x->where('a.auditable_type', $type)->whereIn('a.auditable_id', $ids));
                    }
                }
            })
            ->orderByDesc('a.id')
            ->limit($limit)
            ->selectRaw("a.*, TRIM(CONCAT(COALESCE(u.surname,''),' ',COALESCE(u.name,''))) as actor_name, u.login as actor_login")
            ->get();
    }
}
