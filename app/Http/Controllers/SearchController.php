<?php
namespace App\Http\Controllers;

use App\Domains\Members\Queries\MemberSearchQuery;
use App\Domains\Network\Queries\NetworkSearchQuery;
use App\Domains\Network\Support\MacAddress;
use App\Helpers\MemberType;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Globální hledání: stránka /search (sekce se stropem a celkovým počtem —
 * zbytek přes plnohodnotné seznamy) a našeptávač /search/ajax. Sekce podle
 * práv; síťové jen se zapnutými sítěmi. Dotazy v doménách Members a Network.
 */
class SearchController extends Controller
{
    const SECTION_LIMIT = 50;
    private const QUICK_LIMIT = 5;

    public function __construct(private MemberSearchQuery $members, private NetworkSearchQuery $network) {}

    /** @see MacAddress::normalize() */
    public static function normalizeMac(string $input): ?string
    {
        return MacAddress::normalize($input);
    }

    public function index(Request $request)
    {
        $query   = trim($request->get('q', ''));
        $results = [];
        $totals  = [];

        if (strlen($query) >= 2) {
            $networks = (bool) Setting::get('networks_enabled', 0);
            $sections = [
                'members' => fn () => $this->aclCheck('view_all', 'Members_Controller', 'members') ? $this->members->members($query, self::SECTION_LIMIT) : null,
                'users'   => fn () => $this->aclCheck('view_all', 'Users_Controller', 'users') ? $this->members->users($query, self::SECTION_LIMIT) : null,
                'devices' => fn () => $networks && $this->aclCheck('view_all', 'Devices_Controller', 'devices') ? $this->network->devices($query, self::SECTION_LIMIT) : null,
                'subnets' => fn () => $networks && $this->aclCheck('view_all', 'Subnets_Controller', 'subnet') ? $this->network->subnets($query, self::SECTION_LIMIT) : null,
            ];
            foreach ($sections as $key => $find) {
                [$rows, $total] = $find() ?? [collect(), 0];
                if ($rows->isNotEmpty()) {
                    $results[$key] = $rows;
                    $totals[$key]  = $total;
                }
            }
        }

        return view('search.index', compact('query', 'results', 'totals'));
    }

    public function ajax(Request $request)
    {
        $query = trim($request->get('q', ''));
        if (strlen($query) < 3) return response()->json([]);

        $results = [];
        if ($this->aclCheck('view_all', 'Members_Controller', 'members')) {
            foreach ($this->members->membersQuick($query, self::QUICK_LIMIT) as $m) {
                $results[] = [
                    'url'    => route('members.show', $m->id),
                    'title'  => MemberType::label($m->type) . ' ' . $m->name,
                    'detail' => $m->variable_symbol ? 'VS: ' . $m->variable_symbol : '',
                ];
            }
        }
        if ($this->aclCheck('view_all', 'Users_Controller', 'users')) {
            foreach ($this->members->usersQuick($query, self::QUICK_LIMIT) as $u) {
                $results[] = [
                    'url'    => route('members.show', $u->member_id),
                    'title'  => 'Uživatel ' . $u->login . ' (#' . $u->id . ')',
                    'detail' => $u->contact ?? $u->member_name,
                ];
            }
        }
        if (Setting::get('networks_enabled', 0) && $this->aclCheck('view_all', 'Devices_Controller', 'devices')) {
            foreach ($this->network->devicesQuick($query, self::QUICK_LIMIT) as $d) {
                $results[] = [
                    'url'    => route('devices.show', $d->id),
                    'title'  => 'Zařízení ' . $d->device_name,
                    'detail' => collect([$d->ip_address, $d->ipv6_address, $d->mac])->filter()->implode(' / ') . ' — ' . $d->member_name,
                ];
            }
        }

        return response()->json(array_slice($results, 0, 10));
    }
}
