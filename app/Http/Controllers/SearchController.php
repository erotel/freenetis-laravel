<?php
namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    private function can(string $action, string $section, string $value): bool
    {
        return $this->aclCheck($action, $section, $value);
    }

    // Strop pro každou sekci na /search. Co je nad strop, zobrazí se přes
    // odkaz na plnohodnotný listing (/members, /users, ...) s vlastní paginací.
    const SECTION_LIMIT = 50;

    public function index(Request $request)
    {
        $query   = trim($request->get('q', ''));
        $results = [];
        $totals  = [];

        if (strlen($query) < 2) {
            return view('search.index', compact('query', 'results', 'totals'));
        }

        $like   = '%' . $query . '%';
        // Tokenizace pro multi-slovo dotazy ("zatloukal martin" → najdi i "Martin Zatloukal").
        // Každý token musí matchnout (AND) v daném name fieldu — pořadí slov je jedno.
        $tokens = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        $multi  = count($tokens) > 1;

        // MEMBERS - requires view_all on members
        if ($this->can('view_all', 'Members_Controller', 'members')) {
            $membersQb = DB::table('members as m')
                ->leftJoin('variable_symbols as vs', function($j) {
                    $j->join('accounts as a', 'a.id', '=', 'vs.account_id')
                      ->whereColumn('a.member_id', 'm.id');
                })
                ->leftJoin('address_points as ap', 'ap.id', '=', 'm.address_point_id')
                ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
                ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
                ->where(function($q) use ($like, $query, $tokens, $multi) {
                    $q->where('m.name', 'LIKE', $like)
                      ->orWhere('m.id', '=', is_numeric($query) ? $query : -1)
                      ->orWhere('vs.variable_symbol', 'LIKE', $like)
                      ->orWhere('m.organization_identifier', 'LIKE', $like)
                      ->orWhere('m.vat_organization_identifier', 'LIKE', $like)
                      ->orWhere('t.town', 'LIKE', $like)
                      ->orWhere('s.street', 'LIKE', $like)
                      ->orWhere('ap.street_number', 'LIKE', $like);
                    // Multi-token: každý token musí matchnout někde v compositu
                    // (jméno + ulice + č.p. + obec). Pokrývá dotazy typu
                    // „Stanislava Manharda 19" nebo „Zatloukal Martin Prostějov".
                    if ($multi) {
                        $composite = "CONCAT_WS(' ', m.name, IFNULL(s.street,''), IFNULL(ap.street_number,''), IFNULL(t.town,''))";
                        $q->orWhere(function ($q) use ($tokens, $composite) {
                            foreach ($tokens as $t) {
                                $q->where(DB::raw($composite), 'LIKE', '%' . $t . '%');
                            }
                        });
                    }
                })
                ->select(
                    'm.id', 'm.name', 'm.type',
                    's.street', 'ap.street_number',
                    't.town', 't.zip_code',
                    'vs.variable_symbol'
                )
                ->distinct();

            $total   = (clone $membersQb)->count('m.id');
            $members = $membersQb->limit(self::SECTION_LIMIT)->get();

            if ($members->isNotEmpty()) {
                $results['members'] = $members;
                $totals['members']  = $total;
            }
        }

        // USERS - requires view_all on users
        if ($this->can('view_all', 'Users_Controller', 'users')) {
            $usersQb = DB::table('users as u')
                ->join('members as m', 'm.id', '=', 'u.member_id')
                ->leftJoin('users_contacts as uc', 'uc.user_id', '=', 'u.id')
                ->leftJoin('contacts as c', 'c.id', '=', 'uc.contact_id')
                ->where(function($q) use ($like, $tokens, $multi) {
                    $q->where('u.login', 'LIKE', $like)
                      ->orWhere(DB::raw("CONCAT(u.name, ' ', u.surname)"), 'LIKE', $like)
                      ->orWhere('c.value', 'LIKE', $like);
                    if ($multi) {
                        $q->orWhere(function ($q) use ($tokens) {
                            foreach ($tokens as $t) {
                                $q->where(DB::raw("CONCAT(u.name, ' ', u.surname)"), 'LIKE', '%' . $t . '%');
                            }
                        });
                    }
                })
                ->select('u.id', 'u.login', 'u.name', 'u.surname', 'm.id as member_id', 'm.name as member_name')
                ->distinct();

            $total = (clone $usersQb)->count('u.id');
            $users = $usersQb->limit(self::SECTION_LIMIT)->get();

            if ($users->isNotEmpty()) {
                $results['users'] = $users;
                $totals['users']  = $total;
            }
        }

        // DEVICES + IP + MAC + IPv6 + ADRESA - requires networks_enabled and view_all
        if ($this->can('view_all', 'Devices_Controller', 'devices') && Setting::get('networks_enabled', 0)) {
            $devicesQb = DB::table('devices as d')
                ->join('users as u', 'u.id', '=', 'd.user_id')
                ->join('members as m', 'm.id', '=', 'u.member_id')
                ->leftJoin('ifaces as i', 'i.device_id', '=', 'd.id')
                ->leftJoin('ip_addresses as ip', 'ip.iface_id', '=', 'i.id')
                ->leftJoin('ip6_addresses as ip6', 'ip6.iface_id', '=', 'i.id')
                ->leftJoin('address_points as ap', 'ap.id', '=', 'd.address_point_id')
                ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
                ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
                ->where(function($q) use ($like, $tokens, $multi) {
                    $q->where('d.name', 'LIKE', $like)
                      ->orWhere('i.mac', 'LIKE', $like)
                      ->orWhere('ip.ip_address', 'LIKE', $like)
                      ->orWhere('ip6.ip_address', 'LIKE', $like)
                      ->orWhere('t.town', 'LIKE', $like)
                      ->orWhere('s.street', 'LIKE', $like)
                      ->orWhere('ap.street_number', 'LIKE', $like);
                    // Multi-token: hledá kombinaci ulice + č.p. + obec (např.
                    // „Stanislava Manharda 19 Prostějov") přes composite.
                    if ($multi) {
                        $composite = "CONCAT_WS(' ', d.name, IFNULL(s.street,''), IFNULL(ap.street_number,''), IFNULL(t.town,''))";
                        $q->orWhere(function ($q) use ($tokens, $composite) {
                            foreach ($tokens as $t) {
                                $q->where(DB::raw($composite), 'LIKE', '%' . $t . '%');
                            }
                        });
                    }
                })
                ->select(
                    'd.id', 'd.name as device_name',
                    'i.mac', 'ip.ip_address', 'ip6.ip_address as ipv6_address',
                    'm.id as member_id', 'm.name as member_name',
                    's.street', 'ap.street_number', 't.town'
                )
                ->distinct();

            $total   = (clone $devicesQb)->count('d.id');
            $devices = $devicesQb->limit(self::SECTION_LIMIT)->get();

            if ($devices->isNotEmpty()) {
                $results['devices'] = $devices;
                $totals['devices']  = $total;
            }
        }

        // SUBNETS - requires networks_enabled and view_all
        if ($this->can('view_all', 'Subnets_Controller', 'subnet') && Setting::get('networks_enabled', 0)) {
            $subnetsQb = DB::table('subnets')
                ->where(function($q) use ($like) {
                    $q->where('name', 'LIKE', $like)
                      ->orWhere('network_address', 'LIKE', $like);
                })
                ->select('id', 'name', 'network_address', 'netmask');

            $total   = (clone $subnetsQb)->count();
            $subnets = $subnetsQb->limit(self::SECTION_LIMIT)->get();

            if ($subnets->isNotEmpty()) {
                $results['subnets'] = $subnets;
                $totals['subnets']  = $total;
            }
        }

        return view('search.index', compact('query', 'results', 'totals'));
    }

    public function ajax(Request $request)
    {
        $query = trim($request->get('q', ''));
        if (strlen($query) < 3) return response()->json([]);

        $like    = '%' . $query . '%';
        $tokens  = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        $multi   = count($tokens) > 1;
        $results = [];

        // Members
        if ($this->can('view_all', 'Members_Controller', 'members')) {
            $members = DB::table('members as m')
                ->leftJoin('variable_symbols as vs', function($j) {
                    $j->join('accounts as a', 'a.id', '=', 'vs.account_id')
                      ->whereColumn('a.member_id', 'm.id');
                })
                ->leftJoin('address_points as ap', 'ap.id', '=', 'm.address_point_id')
                ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
                ->where(function($q) use ($like, $query, $tokens, $multi) {
                    $q->where('m.name', 'LIKE', $like)
                      ->orWhere('vs.variable_symbol', 'LIKE', $like)
                      ->orWhere('m.organization_identifier', 'LIKE', $like)
                      ->orWhere('t.town', 'LIKE', $like);
                    if ($multi) {
                        $q->orWhere(function ($q) use ($tokens) {
                            foreach ($tokens as $t) {
                                $q->where('m.name', 'LIKE', '%' . $t . '%');
                            }
                        });
                    }
                })
                ->select('m.id', 'm.name', 'm.type', 'vs.variable_symbol')
                ->distinct()->limit(5)->get();

            foreach ($members as $m) {
                $results[] = [
                    'url'    => route('members.show', $m->id),
                    'title'  => \App\Helpers\MemberType::label($m->type) . ' ' . $m->name,
                    'detail' => $m->variable_symbol ? 'VS: ' . $m->variable_symbol : '',
                ];
            }
        }

        // Users (login, email)
        if ($this->can('view_all', 'Users_Controller', 'users')) {
            $users = DB::table('users as u')
                ->join('members as m', 'm.id', '=', 'u.member_id')
                ->leftJoin('users_contacts as uc', 'uc.user_id', '=', 'u.id')
                ->leftJoin('contacts as c', 'c.id', '=', 'uc.contact_id')
                ->where(function($q) use ($like, $tokens, $multi) {
                    $q->where('u.login', 'LIKE', $like)
                      ->orWhere('c.value', 'LIKE', $like);
                    if ($multi) {
                        $q->orWhere(function ($q) use ($tokens) {
                            foreach ($tokens as $t) {
                                $q->where(DB::raw("CONCAT(u.name, ' ', u.surname)"), 'LIKE', '%' . $t . '%');
                            }
                        });
                    }
                })
                ->select('m.id as member_id', 'm.name as member_name', 'u.login', 'c.value as contact')
                ->distinct()->limit(5)->get();

            foreach ($users as $u) {
                $results[] = [
                    'url'    => route('members.show', $u->member_id),
                    'title'  => 'Uživatel ' . $u->login,
                    'detail' => $u->contact ?? $u->member_name,
                ];
            }
        }

        // Devices/IP/MAC/IPv6/adresa
        if ($this->can('view_all', 'Devices_Controller', 'devices') && Setting::get('networks_enabled', 0)) {
            $devices = DB::table('devices as d')
                ->join('users as u', 'u.id', '=', 'd.user_id')
                ->join('members as m', 'm.id', '=', 'u.member_id')
                ->leftJoin('ifaces as i', 'i.device_id', '=', 'd.id')
                ->leftJoin('ip_addresses as ip', 'ip.iface_id', '=', 'i.id')
                ->leftJoin('ip6_addresses as ip6', 'ip6.iface_id', '=', 'i.id')
                ->leftJoin('address_points as ap', 'ap.id', '=', 'd.address_point_id')
                ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
                ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
                ->where(function($q) use ($like) {
                    $q->where('d.name', 'LIKE', $like)
                      ->orWhere('i.mac', 'LIKE', $like)
                      ->orWhere('ip.ip_address', 'LIKE', $like)
                      ->orWhere('ip6.ip_address', 'LIKE', $like)
                      ->orWhere('t.town', 'LIKE', $like)
                      ->orWhere('s.street', 'LIKE', $like)
                      ->orWhere('ap.street_number', 'LIKE', $like);
                })
                ->select('d.id', 'd.name as device_name', 'i.mac', 'ip.ip_address', 'ip6.ip_address as ipv6_address', 'm.id as member_id', 'm.name as member_name')
                ->distinct()->limit(5)->get();

            foreach ($devices as $d) {
                $detail = collect([$d->ip_address, $d->ipv6_address, $d->mac])->filter()->implode(' / ');
                $results[] = [
                    'url'    => route('devices.show', $d->id),
                    'title'  => 'Zařízení ' . $d->device_name,
                    'detail' => $detail . ' — ' . $d->member_name,
                ];
            }
        }

        return response()->json(array_slice($results, 0, 10));
    }
}
