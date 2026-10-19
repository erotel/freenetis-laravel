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

    /**
     * Globální prohlížeč: fulltext v old/new_values (najde i konkrétní hodnotu,
     * např. smazanou IP), číslo i v id záznamu; uživatel id nebo část loginu.
     * Filtr na occurred_at zároveň umožní partition pruning.
     *
     * @param array{q:string, type:string, action:string, user:string, from:?string, to:?string} $f
     */
    public function search(array $f, int $perPage)
    {
        $query = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('members as mem', 'mem.id', '=', 'u.member_id')
            ->selectRaw("a.*, TRIM(CONCAT(COALESCE(u.surname,''),' ',COALESCE(u.name,''))) as actor_name, u.login as actor_login, u.member_id as actor_member_id, mem.name as actor_member_name");

        if ($f['q'] !== '') {
            $q = $f['q'];
            $query->where(function ($w) use ($q) {
                $w->where('a.old_values', 'like', "%{$q}%")
                  ->orWhere('a.new_values', 'like', "%{$q}%");
                if (ctype_digit($q)) {
                    $w->orWhere('a.auditable_id', (int) $q);
                }
            });
        }
        if ($f['type'] !== '') {
            $query->where('a.auditable_type', $f['type']);
        }
        if ($f['action'] !== '') {
            $query->where('a.action', $f['action']);
        }
        if ($f['user'] !== '') {
            if (ctype_digit($f['user'])) {
                $query->where('a.user_id', (int) $f['user']);
            } else {
                $query->where('u.login', 'like', "%{$f['user']}%");
            }
        }
        if ($f['from']) {
            $query->where('a.occurred_at', '>=', $f['from'] . ' 00:00:00');
        }
        if ($f['to']) {
            $query->where('a.occurred_at', '<=', $f['to'] . ' 23:59:59');
        }

        return $query->orderByDesc('a.id')->paginate($perPage)->withQueryString();
    }

    /** Typy entit pro výběr (tabulka je zatím malá; kdyby narostla, statický seznam). */
    public function types(): Collection
    {
        return DB::table('audit_logs')->select('auditable_type')->distinct()
            ->orderBy('auditable_type')->pluck('auditable_type');
    }
}
