<?php

namespace App\Domains\Members\Queries;

use App\Http\Filters\MemberFilter;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Čtecí dotazy nad seznamy členů (read model — nic nemění). Řazení, typy
 * a stránkování musí volající předat už ověřené (allowlist).
 */
class MemberListQuery
{
    public const SORTS     = ['id', 'name', 'type', 'entrance_date', 'registration'];
    public const PER_PAGES = [50, 100, 150, 200, 250, 300, 350, 400, 450, 500];

    /**
     * Seznam členů s kreditem, typem přesměrování a whitelistem.
     *
     * @param int[]  $types   prázdné = všechny
     * @param string $locked  'all' | '0' | '1'
     */
    public function paginate(string $search, array $types, string $locked, array $advancedFilters,
                             string $sort, string $dir, int $perPage): LengthAwarePaginator
    {
        $query = Member::query()
            ->select([
                'members.id',
                'members.name',
                'members.type',
                'members.registration',
                'members.entrance_date',
                'members.leaving_date',
                'members.locked',
                'members.address_point_id',
                'members.tv_active',
                'members.tv_valid_until',
                'members.tv_synced_at',
                'members.payment_blocked',
                'members.payment_blocked_since',
                'members.pending_termination',
                DB::raw('(SELECT a.balance FROM accounts a WHERE a.member_id = members.id AND a.account_attribute_id = 221100 LIMIT 1) AS credit_balance'),
                DB::raw('(SELECT msg.type FROM messages_ip_addresses mia JOIN ip_addresses ip ON ip.id = mia.ip_address_id JOIN messages msg ON msg.id = mia.message_id WHERE ip.member_id = members.id LIMIT 1) AS redirect_type'),
                DB::raw("(SELECT CASE WHEN mw.permanent = 1 OR mw.until = '9999-12-31' THEN 'permanent' ELSE 'temporary' END FROM members_whitelists mw WHERE mw.member_id = members.id AND mw.since <= CURDATE() AND mw.until >= CURDATE() LIMIT 1) AS whitelist_type"),
            ])
            ->with(['addressPoint.town', 'addressPoint.street']);

        if ($search !== '') {
            $this->applySearch($query, $search);
        }
        if (!empty($types)) {
            $query->whereIn('members.type', $types);
        }
        if ($locked !== 'all') {
            $query->where('members.locked', (int) $locked);
        }
        if (!empty($advancedFilters)) {
            MemberFilter::apply($query, $advancedFilters);
        }

        return $query->orderBy('members.' . $sort, $dir)
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Stejná pole jako SearchController (jméno, IČO/DIČ, poznámka, id, VS,
     * adresa, víc slov přes jméno+adresu) — odkaz „Otevřít všechny v seznamu"
     * z /search tak dojde ke stejné množině.
     */
    private function applySearch($query, string $search): void
    {
        $like   = '%' . $search . '%';
        $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        $multi  = count($tokens) > 1;

        $query->where(function ($q) use ($search, $like, $tokens, $multi) {
            $q->where('members.name', 'like', $like)
              ->orWhere('members.organization_identifier', 'like', $like)
              ->orWhere('members.vat_organization_identifier', 'like', $like)
              ->orWhere('members.comment', 'like', $like);

            if (is_numeric($search)) {
                $q->orWhere('members.id', (int) $search);
            }

            $q->orWhereExists(function ($sub) use ($like) {
                $sub->from('variable_symbols as vs')
                    ->join('accounts as a', 'a.id', '=', 'vs.account_id')
                    ->whereColumn('a.member_id', 'members.id')
                    ->where('vs.variable_symbol', 'like', $like);
            });

            $q->orWhereExists(function ($sub) use ($like) {
                $sub->from('address_points as ap')
                    ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
                    ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
                    ->whereColumn('ap.id', 'members.address_point_id')
                    ->where(function ($qq) use ($like) {
                        $qq->where('t.town', 'like', $like)
                           ->orWhere('s.street', 'like', $like)
                           ->orWhere('ap.street_number', 'like', $like);
                    });
            });

            if ($multi) {
                // Každý token musí sednout v jméně, ulici, č.p. nebo obci.
                $q->orWhere(function ($qq) use ($tokens) {
                    foreach ($tokens as $t) {
                        $qq->whereExists(function ($sub) use ($t) {
                            $sub->from('address_points as ap')
                                ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
                                ->leftJoin('towns as tn', 'tn.id', '=', 'ap.town_id')
                                ->whereColumn('ap.id', 'members.address_point_id')
                                ->whereRaw(
                                    "CONCAT_WS(' ', members.name, IFNULL(s.street,''), IFNULL(ap.street_number,''), IFNULL(tn.town,'')) LIKE ?",
                                    ['%' . $t . '%']
                                );
                        });
                    }
                });
            }
        });
    }

    /** Čekatelé (17/18) bez data odchodu, nejnovější první. */
    public function applicants(): Collection
    {
        return DB::table('members as m')
            ->whereIn('m.type', [17, 18])
            ->where(function ($q) {
                $q->where('m.leaving_date', '9999-12-31')
                  ->orWhere('m.leaving_date', '0000-00-00');
            })
            ->orderByDesc('m.id')
            ->select('m.id', 'm.name', 'm.type', 'm.entrance_date', 'm.registration')
            ->get();
    }

    /**
     * Označení k ukončení (cron MarkPendingTermination) — jen zákazníci a řádní
     * členové, s kreditem a VS, nejdéle blokovaní první.
     */
    public function pendingTermination(): Collection
    {
        return DB::table('members as m')
            ->join('accounts as a', function ($j) {
                $j->on('a.member_id', '=', 'm.id')->where('a.account_attribute_id', 221100);
            })
            ->leftJoin('variable_symbols as vs', 'vs.account_id', '=', 'a.id')
            ->where('m.pending_termination', 1)
            ->whereIn('m.type', [2, 90])
            ->select(
                'm.id', 'm.name', 'm.type',
                'm.payment_blocked_since',
                'a.balance',
                DB::raw('GROUP_CONCAT(vs.variable_symbol) AS variable_symbols')
            )
            ->groupBy('m.id', 'm.name', 'm.type', 'm.payment_blocked_since', 'a.balance')
            ->orderBy('m.payment_blocked_since')
            ->get();
    }
}
