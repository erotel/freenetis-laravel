<?php

namespace App\Domains\Members\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Globální hledání — členové (jméno, ID, VS, IČ/DIČ, poznámka, adresa) a
 * uživatelé (login, user ID, jméno, kontakty). Víceslovný dotaz = AND tokenů
 * v libovolném pořadí (u členů přes jméno + adresu).
 */
class MemberSearchQuery
{
    /** @return array{0: Collection, 1: int} [max $limit řádků, celkem] */
    public function members(string $query, int $limit): array
    {
        $like   = '%' . $query . '%';
        $tokens = self::tokens($query);

        $qb = DB::table('members as m')
            ->leftJoin('variable_symbols as vs', function ($j) {
                $j->join('accounts as a', 'a.id', '=', 'vs.account_id')->whereColumn('a.member_id', 'm.id');
            })
            ->leftJoin('address_points as ap', 'ap.id', '=', 'm.address_point_id')
            ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
            ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
            ->where(function ($q) use ($like, $query, $tokens) {
                $q->where('m.name', 'LIKE', $like)
                  ->orWhere('m.id', '=', is_numeric($query) ? $query : -1)
                  ->orWhere('vs.variable_symbol', 'LIKE', $like)
                  ->orWhere('m.organization_identifier', 'LIKE', $like)
                  ->orWhere('m.vat_organization_identifier', 'LIKE', $like)
                  ->orWhere('m.comment', 'LIKE', $like)
                  ->orWhere('t.town', 'LIKE', $like)
                  ->orWhere('s.street', 'LIKE', $like)
                  ->orWhere('ap.street_number', 'LIKE', $like);
                if (count($tokens) > 1) {
                    self::allTokens($q, $tokens, "CONCAT_WS(' ', m.name, IFNULL(s.street,''), IFNULL(ap.street_number,''), IFNULL(t.town,''))");
                }
            })
            ->select('m.id', 'm.name', 'm.type', 's.street', 'ap.street_number', 't.town', 't.zip_code', 'vs.variable_symbol')
            ->distinct();

        return [(clone $qb)->limit($limit)->get(), (clone $qb)->count('m.id')];
    }

    /** @return array{0: Collection, 1: int} */
    public function users(string $query, int $limit): array
    {
        $like   = '%' . $query . '%';
        $tokens = self::tokens($query);

        $qb = $this->usersBase()
            ->where(function ($q) use ($like, $query, $tokens) {
                $q->where('u.login', 'LIKE', $like)
                  ->orWhere('u.id', '=', ctype_digit($query) ? (int) $query : -1)
                  ->orWhere(DB::raw("CONCAT(u.name, ' ', u.surname)"), 'LIKE', $like)
                  ->orWhere('c.value', 'LIKE', $like);
                if (count($tokens) > 1) {
                    self::allTokens($q, $tokens, "CONCAT(u.name, ' ', u.surname)");
                }
            })
            ->select('u.id', 'u.login', 'u.name', 'u.surname', 'm.id as member_id', 'm.name as member_name')
            ->distinct();

        return [(clone $qb)->limit($limit)->get(), (clone $qb)->count('u.id')];
    }

    /** Našeptávač: členové podle jména, VS, IČ, obce. */
    public function membersQuick(string $query, int $limit): Collection
    {
        $like   = '%' . $query . '%';
        $tokens = self::tokens($query);

        return DB::table('members as m')
            ->leftJoin('variable_symbols as vs', function ($j) {
                $j->join('accounts as a', 'a.id', '=', 'vs.account_id')->whereColumn('a.member_id', 'm.id');
            })
            ->leftJoin('address_points as ap', 'ap.id', '=', 'm.address_point_id')
            ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
            ->where(function ($q) use ($like, $tokens) {
                $q->where('m.name', 'LIKE', $like)
                  ->orWhere('vs.variable_symbol', 'LIKE', $like)
                  ->orWhere('m.organization_identifier', 'LIKE', $like)
                  ->orWhere('t.town', 'LIKE', $like);
                if (count($tokens) > 1) {
                    self::allTokens($q, $tokens, 'm.name');
                }
            })
            ->select('m.id', 'm.name', 'm.type', 'vs.variable_symbol')
            ->distinct()->limit($limit)->get();
    }

    /** Našeptávač: uživatelé podle loginu, user ID, kontaktu (víc slov přes jméno). */
    public function usersQuick(string $query, int $limit): Collection
    {
        $like   = '%' . $query . '%';
        $tokens = self::tokens($query);

        return $this->usersBase()
            ->where(function ($q) use ($like, $query, $tokens) {
                $q->where('u.login', 'LIKE', $like)
                  ->orWhere('u.id', '=', ctype_digit($query) ? (int) $query : -1)
                  ->orWhere('c.value', 'LIKE', $like);
                if (count($tokens) > 1) {
                    self::allTokens($q, $tokens, "CONCAT(u.name, ' ', u.surname)");
                }
            })
            ->select('u.id', 'm.id as member_id', 'm.name as member_name', 'u.login', 'c.value as contact')
            ->distinct()->limit($limit)->get();
    }

    private function usersBase(): Builder
    {
        return DB::table('users as u')
            ->join('members as m', 'm.id', '=', 'u.member_id')
            ->leftJoin('users_contacts as uc', 'uc.user_id', '=', 'u.id')
            ->leftJoin('contacts as c', 'c.id', '=', 'uc.contact_id');
    }

    /** @return string[] */
    public static function tokens(string $query): array
    {
        return preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
    }

    /** OR (každý token LIKE ve výrazu). */
    public static function allTokens($q, array $tokens, string $expression): void
    {
        $q->orWhere(function ($q) use ($tokens, $expression) {
            foreach ($tokens as $t) {
                $q->where(DB::raw($expression), 'LIKE', '%' . $t . '%');
            }
        });
    }
}
