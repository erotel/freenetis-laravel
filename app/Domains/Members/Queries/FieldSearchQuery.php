<?php

namespace App\Domains\Members\Queries;

use App\Domains\Members\Support\FieldPresenter;
use App\Helpers\MemberType;
use App\Models\AccountAttribute;
use App\Models\Contact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Živé hledání Field Mode: max 20 členů podle relevance + max 10 zařízení
 * (nahoře — specifičtější než vlastník). Kandidáti z cílených dotazů se
 * sub-limitem 40 (jméno/ID/IČO, VS, adresa, kontakt; se sítěmi i IP a jméno
 * zařízení). Víceslovný dotaz = AND tokenů v libovolném pořadí.
 *
 * „Zaplaceno do" se v seznamu nepočítá (drahé, běželo by při každém stisku) —
 * stav jen z locked / bloku / výpovědi / znaménka salda; datum je na kartě.
 */
class FieldSearchQuery
{
    public function search(string $query, bool $networks): Collection
    {
        if (mb_strlen($query) < 2) {
            return collect();
        }

        $like    = '%' . $query . '%';
        $ids     = $this->candidateIds($query, $like, $networks);
        $members = $ids->isEmpty() ? collect() : $this->members($ids, $query);

        // zařízení i bez shody člena (např. IP zařízení bez vlastníka)
        return $this->matchingDevices($query, $like, $networks)->concat($members)->values();
    }

    private function candidateIds(string $query, string $like, bool $networks): Collection
    {
        $tokens = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        $multi  = count($tokens) > 1;
        $ids    = collect();

        // Jméno je v jednom poli („Martin Zatloukal") → víc slov jako AND tokenů.
        $ids = $ids->merge(
            DB::table('members as m')
                ->where(function ($q) use ($like, $query, $tokens, $multi) {
                    $q->where('m.name', 'LIKE', $like)
                      ->orWhere('m.id', '=', ctype_digit($query) ? (int) $query : -1)
                      ->orWhere('m.organization_identifier', 'LIKE', $like);
                    if ($multi) {
                        $q->orWhere(function ($q) use ($tokens) {
                            foreach ($tokens as $t) {
                                $q->where('m.name', 'LIKE', '%' . $t . '%');
                            }
                        });
                    }
                })
                ->limit(40)->pluck('m.id')
        );

        $ids = $ids->merge(
            DB::table('variable_symbols as vs')
                ->join('accounts as a', 'a.id', '=', 'vs.account_id')
                ->where('vs.variable_symbol', 'LIKE', $like)
                ->limit(40)->pluck('a.member_id')
        );

        // Adresa: víc slov („krumsín 167") přes composite „ulice číslo obec".
        $ids = $ids->merge(
            DB::table('members as m')
                ->join('address_points as ap', 'ap.id', '=', 'm.address_point_id')
                ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
                ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
                ->where(function ($q) use ($like, $tokens, $multi) {
                    $q->where('ap.street_number', 'LIKE', $like)
                      ->orWhere('s.street', 'LIKE', $like)
                      ->orWhere('t.town', 'LIKE', $like);
                    if ($multi) {
                        $composite = "CONCAT_WS(' ', IFNULL(s.street,''), IFNULL(ap.street_number,''), IFNULL(t.town,''))";
                        $q->orWhere(function ($q) use ($tokens, $composite) {
                            foreach ($tokens as $t) {
                                $q->where(DB::raw($composite), 'LIKE', '%' . $t . '%');
                            }
                        });
                    }
                })
                ->limit(40)->pluck('m.id')
        );

        $ids = $ids->merge(
            DB::table('contacts as c')
                ->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')
                ->join('users as u', 'u.id', '=', 'uc.user_id')
                ->whereIn('c.type', [Contact::TYPE_EMAIL, Contact::TYPE_PHONE])
                ->where('c.value', 'LIKE', $like)
                ->whereNotNull('u.member_id')
                ->limit(40)->pluck('u.member_id')
        );

        if ($networks) {
            // IP přímo členovi (menšina) …
            $ids = $ids->merge(
                DB::table('ip_addresses')
                    ->where('ip_address', 'LIKE', $like)
                    ->whereNotNull('member_id')
                    ->limit(40)->pluck('member_id')
            );
            // … i přes zařízení (ip / ip6 → iface → device → user → member).
            $ids = $ids->merge(
                DB::table('ip_addresses as ip')
                    ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
                    ->join('devices as d', 'd.id', '=', 'i.device_id')
                    ->join('users as u', 'u.id', '=', 'd.user_id')
                    ->where('ip.ip_address', 'LIKE', $like)
                    ->whereNotNull('u.member_id')
                    ->limit(40)->pluck('u.member_id')
            );
            $ids = $ids->merge(
                DB::table('ip6_addresses as ip')
                    ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
                    ->join('devices as d', 'd.id', '=', 'i.device_id')
                    ->join('users as u', 'u.id', '=', 'd.user_id')
                    ->where('ip.ip_address', 'LIKE', $like)
                    ->whereNotNull('u.member_id')
                    ->limit(40)->pluck('u.member_id')
            );
            $ids = $ids->merge(
                DB::table('devices as d')
                    ->join('users as u', 'u.id', '=', 'd.user_id')
                    ->where('d.name', 'LIKE', $like)
                    ->whereNotNull('u.member_id')
                    ->limit(40)->pluck('u.member_id')
            );
        }

        return $ids->filter()->unique()->values();
    }

    /** Řádky členů seřazené lehkou relevancí (přesný VS / ID, prefix jména), max 20. */
    private function members(Collection $ids, string $query): Collection
    {
        $rows = DB::table('members as m')
            ->leftJoin('address_points as ap', 'ap.id', '=', 'm.address_point_id')
            ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
            ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
            ->leftJoin('accounts as acc', function ($j) {
                $j->on('acc.member_id', '=', 'm.id')
                  ->where('acc.account_attribute_id', '=', AccountAttribute::CREDIT_ATTRIBUTE_ID);
            })
            ->whereIn('m.id', $ids)
            ->select(
                'm.id', 'm.name', 'm.type', 'm.locked',
                'm.payment_blocked', 'm.pending_termination',
                's.street', 'ap.street_number', 't.town',
                'acc.balance'
            )
            ->get();

        // jeden (nejnižší) VS na člena
        $vsMap = DB::table('variable_symbols as vs')
            ->join('accounts as a', 'a.id', '=', 'vs.account_id')
            ->whereIn('a.member_id', $ids)
            ->groupBy('a.member_id')
            ->select('a.member_id', DB::raw('MIN(vs.variable_symbol) as vs'))
            ->pluck('vs', 'a.member_id');

        $q = mb_strtolower($query);
        return $rows->map(function ($r) use ($vsMap, $q) {
            $balance = $r->balance !== null ? (float) $r->balance : null;
            $vs      = $vsMap[$r->id] ?? null;

            $score = 0;
            if ($vs !== null && (string) $vs === $q)                   $score += 100;
            if ((string) $r->id === $q)                                 $score += 100;
            if (str_starts_with(mb_strtolower((string) $r->name), $q)) $score += 50;

            return [
                'kind'       => 'member',
                'id'         => (int) $r->id,
                'name'       => $r->name,
                'vs'         => $vs,
                'type_label' => MemberType::label((int) $r->type),
                'address'    => FieldPresenter::address($r->street, $r->street_number, $r->town),
                'status'     => FieldPresenter::status((bool) $r->locked, $balance, (bool) $r->payment_blocked, (bool) $r->pending_termination),
                'balance'    => $balance,
                '_score'     => $score,
            ];
        })
        ->sortByDesc('_score')
        ->take(20)
        ->map(function ($row) { unset($row['_score']); return $row; })
        ->values();
    }

    /** Zařízení podle jména nebo IP v4/v6, detail = matchnutá (jinak první) IP · vlastník. */
    private function matchingDevices(string $query, string $like, bool $networks): Collection
    {
        if (!$networks) {
            return collect();
        }

        $devices = DB::table('devices as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('members as m', 'm.id', '=', 'u.member_id')
            ->leftJoin('ifaces as i', 'i.device_id', '=', 'd.id')
            ->leftJoin('ip_addresses as ip', 'ip.iface_id', '=', 'i.id')
            ->leftJoin('ip6_addresses as ip6', 'ip6.iface_id', '=', 'i.id')
            ->where(function ($q) use ($like) {
                $q->where('d.name', 'LIKE', $like)
                  ->orWhere('ip.ip_address', 'LIKE', $like)
                  ->orWhere('ip6.ip_address', 'LIKE', $like);
            })
            ->select('d.id', 'd.name', 'm.name as member_name')
            ->distinct()
            ->limit(10)
            ->get();

        if ($devices->isEmpty()) {
            return collect();
        }

        $devIds = $devices->pluck('id');
        $ipMap  = [];
        $rowsV4 = DB::table('ip_addresses as ip')->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->whereIn('i.device_id', $devIds)->select('i.device_id', 'ip.ip_address')->get();
        $rowsV6 = DB::table('ip6_addresses as ip')->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->whereIn('i.device_id', $devIds)->select('i.device_id', 'ip.ip_address')->get();
        foreach ($rowsV4->concat($rowsV6) as $r) {
            $ipMap[$r->device_id][] = $r->ip_address;
        }

        return $devices->map(function ($d) use ($ipMap, $query) {
            $ips    = collect($ipMap[$d->id] ?? []);
            $showIp = $ips->first(fn ($x) => stripos($x, $query) !== false) ?? $ips->first();
            return [
                'kind'   => 'device',
                'id'     => (int) $d->id,
                'name'   => $d->name,
                'detail' => collect([$showIp, $d->member_name])->filter()->implode(' · '),
            ];
        })->values();
    }
}
