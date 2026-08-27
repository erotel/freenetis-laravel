<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Http\Controllers\Concerns\SyncsIp6Address;
use App\Http\Filters\DeviceFilter;
use App\Models\ConnectionRequest;
use App\Models\Device;
use App\Models\DeviceEngineer;
use App\Models\DeviceTemplate;
use App\Models\EnumType;
use App\Models\Iface;
use App\Models\IpAddress;
use App\Models\Setting;
use App\Models\Subnet;
use App\Models\User;
use App\Services\AllowedSubnetSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    use SyncsIp6Address;
    private const ACL_SECTION = 'Devices_Controller';
    private const ACL_VALUE   = 'devices';

    /**
     * WPA2 klíč: prázdný string → null; klíč dává smysl jen u AP, u ostatních
     * typů ho zahodíme (např. při přetypování AP → switch se klíč vymaže).
     * Působí jen když je `wpa_key` v datech (tj. uživatel má právo na heslo).
     */
    private function normalizeWpaKey(array $data): array
    {
        if (!array_key_exists('wpa_key', $data)) {
            return $data;
        }
        $key = trim((string) ($data['wpa_key'] ?? ''));
        $isAp = (int) ($data['type'] ?? 0) === Device::AP_TYPE_ID;
        $data['wpa_key'] = ($isAp && $key !== '') ? $key : null;

        return $data;
    }

    private function can(string $action, string $value = self::ACL_VALUE): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, $value);
    }

    /**
     * Member picker pro device formuláře. Vrací jen členy, kteří mají main usera
     * (type=1) — pro ostatní se nemá co uložit do devices.user_id. Pokud má člen
     * víc main userů (legacy data), bere nejnižší id.
     *
     * Třídění podle příjmení (u.surname) → jméno → login. members.name je sice
     * "Jméno Příjmení", ale picker řadí podle příjmení, jak admin čeká.
     */
    private function loadMembersForPicker(): \Illuminate\Support\Collection
    {
        return DB::table('members as m')
            ->join('users as u', function ($j) {
                $j->on('u.member_id', '=', 'm.id')
                  ->where('u.type', '=', User::MAIN_USER);
            })
            // Adresa umístění člena — zdroj předvyplnění u zařízení (address_point sdílený).
            ->leftJoin('address_points as ap', 'ap.id', '=', 'm.address_point_id')
            ->leftJoin('streets as st', 'st.id', '=', 'ap.street_id')
            ->leftJoin('towns as tn', 'tn.id', '=', 'ap.town_id')
            ->select(
                'm.id', 'm.name', 'u.id as user_id', 'u.login', 'u.surname', 'u.name as user_name',
                'm.address_point_id',
                'ap.town_id as ap_town_id', 'ap.street_id as ap_street_id', 'ap.street_number as ap_street_number',
                'st.street as ap_street',
                'tn.town as ap_town', 'tn.zip_code as ap_zip'
            )
            ->orderBy('u.surname')
            ->orderBy('u.name')
            ->orderBy('u.id')
            ->get()
            ->unique('id')
            ->values()
            ->map(function ($row) {
                // Pro fyzické osoby zobrazujeme "Příjmení Jméno" (sjednoceno se sortem).
                // Pokud surname/name chybí (organizace, legacy), fallback na members.name.
                $surname = trim((string) $row->surname);
                $name    = trim((string) $row->user_name);
                $row->display_name = ($surname !== '' && $name !== '')
                    ? $surname . ' ' . $name
                    : $row->name;

                // Čitelný popis adresy umístění (stejný formát jako devices/show).
                $street = trim(($row->ap_street ?? '') . ' ' . ($row->ap_street_number ?? ''));
                $town   = trim(($row->ap_town ?? '') . ' ' . ($row->ap_zip ?? ''));
                $row->address_label = trim(trim($street) . (($street && $town) ? ', ' : '') . $town);

                return $row;
            });
    }

    /**
     * True, pokud daný uživatel patří pod stejného člena jako přihlášený uživatel
     * (self-access — zákazník smí vidět vlastní zařízení i bez ACL view_all).
     */
    private function isOwnUser(?int $userId): bool
    {
        $ownMemberId = auth()->user()?->member_id;
        if (!$ownMemberId || !$userId) {
            return false;
        }

        return DB::table('users')->where('id', $userId)->value('member_id') == $ownMemberId;
    }

    /**
     * Najde main user_id pro daného člena. Vrací null pokud člen nebo main user neexistuje.
     */
    private function resolveMainUserId(int $memberId): ?int
    {
        return DB::table('users')
            ->where('member_id', $memberId)
            ->where('type', User::MAIN_USER)
            ->orderBy('id')
            ->value('id');
    }

    public function index(Request $request)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $allowedSorts = ['id', 'name', 'type', 'user_id', 'access_time'];
        $sort = in_array($request->query('sort'), $allowedSorts, true)
            ? $request->query('sort')
            : 'id';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->query('record_per_page', 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300, 350, 400, 450, 500], true)) {
            $perPage = 50;
        }

        $search = trim((string) $request->query('search', ''));

        $query = Device::with(['user', 'enumType']);

        if ($search !== '') {
            // Stejná pole jako SearchController — název, MAC, IPv4, IPv6 a adresa
            // (town/street/č.p. + multi-token composite), aby odkaz „Otevřít všechny
            // v seznamu" z /search seděl.
            $like   = '%' . $search . '%';
            $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
            $multi  = count($tokens) > 1;
            $query->where(function ($q) use ($like, $tokens, $multi) {
                $q->where('devices.name', 'like', $like);
                $q->orWhereExists(function ($sub) use ($like) {
                    $sub->from('ifaces as i')
                        ->whereColumn('i.device_id', 'devices.id')
                        ->where('i.mac', 'like', $like);
                });
                $q->orWhereExists(function ($sub) use ($like) {
                    $sub->from('ifaces as i')
                        ->join('ip_addresses as ip', 'ip.iface_id', '=', 'i.id')
                        ->whereColumn('i.device_id', 'devices.id')
                        ->where('ip.ip_address', 'like', $like);
                });
                $q->orWhereExists(function ($sub) use ($like) {
                    $sub->from('ifaces as i')
                        ->join('ip6_addresses as ip6', 'ip6.iface_id', '=', 'i.id')
                        ->whereColumn('i.device_id', 'devices.id')
                        ->where('ip6.ip_address', 'like', $like);
                });
                $q->orWhereExists(function ($sub) use ($like) {
                    $sub->from('address_points as ap')
                        ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
                        ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
                        ->whereColumn('ap.id', 'devices.address_point_id')
                        ->where(function ($qq) use ($like) {
                            $qq->where('t.town', 'like', $like)
                               ->orWhere('s.street', 'like', $like)
                               ->orWhere('ap.street_number', 'like', $like);
                        });
                });
                if ($multi) {
                    $q->orWhere(function ($qq) use ($tokens) {
                        foreach ($tokens as $t) {
                            $qq->whereExists(function ($sub) use ($t) {
                                $sub->from('address_points as ap')
                                    ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
                                    ->leftJoin('towns as tn', 'tn.id', '=', 'ap.town_id')
                                    ->whereColumn('ap.id', 'devices.address_point_id')
                                    ->whereRaw(
                                        "CONCAT_WS(' ', devices.name, IFNULL(s.street,''), IFNULL(ap.street_number,''), IFNULL(tn.town,'')) LIKE ?",
                                        ['%' . $t . '%']
                                    );
                            });
                        }
                    });
                }
            });
        }

        $advancedFilters = $request->input('filters', []);
        if (!empty($advancedFilters)) {
            DeviceFilter::apply($query, $advancedFilters);
        }

        $devices = $query->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();

        return view('devices.index', [
            'devices'        => $devices,
            'sort'           => $sort,
            'dir'            => $dir,
            'perPage'        => $perPage,
            'search'         => $search,
            'filterFields'   => DeviceFilter::fields(),
            'currentFilters' => $advancedFilters,
            'canNew'         => $this->can('new_all'),
            'canEdit'        => $this->can('edit_all'),
            'canDelete'      => $this->can('delete_all'),
        ]);
    }

    public function showByUser(int $userId)
    {
        if (!$this->can('view_all') && !$this->isOwnUser($userId)) {
            abort(403);
        }

        $user = User::find($userId);
        if (!$user) {
            abort(404);
        }

        $sort = in_array(request('sort'), ['id', 'name'], true) ? request('sort') : 'name';
        $dir  = request('dir') === 'desc' ? 'desc' : 'asc';

        $devices = Device::with(['enumType', 'ifaces.ipAddresses.subnet'])
            ->where('user_id', $userId)
            ->orderBy($sort, $dir)
            ->get();

        return view('devices.show_by_user', [
            'user'      => $user,
            'devices'   => $devices,
            'sort'      => $sort,
            'dir'       => $dir,
            'canNew'    => $this->can('new_all'),
            'canEdit'   => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
            'canViewUser' => $this->aclCheck('view_all', 'Users_Controller', 'users'),
            // Self-service: vlastník smí požádat o nové připojení (stejně jako SNMP-unknown flow)
            'canRequestConnection' => $this->isOwnUser($userId)
                && (bool) Setting::get('connection_request_enable')
                && $this->aclCheck('new_own', 'Connection_Requests_Controller', 'request'),
            'memberId' => $user->member_id,
        ]);
    }

    public function show(int $id)
    {
        $device = Device::with([
            'user.member',
            'enumType',
            'addressPoint.street',
            'addressPoint.town',
            'ifaces.ipAddresses.subnet',
            'ifaces.ip6Addresses',
            'deviceEngineers.user',
        ])->find($id);

        if (!$device) {
            abort(404);
        }

        if (!$this->can('view_all') && !$this->isOwnUser($device->user_id)) {
            abort(403);
        }

        $canManageEngineers = $this->can('new_all', 'engineer');
        $canDeleteEngineer  = $this->can('delete_all', 'engineer');

        $assignedEngineerIds = $device->deviceEngineers->pluck('user_id')->all();
        $engineerUsers = $canManageEngineers
            ? User::orderBy('surname')->orderBy('name')
                ->whereNotIn('id', $assignedEngineerIds)
                ->get(['id', 'login', 'name', 'surname'])
            : collect();

        // PPPoE přístupy (per iface) — jen když je modul zapnutý a uživatel smí
        // vidět hesla. Klíčováno iface_id pro snadné dohledání v šabloně.
        $pppoeEnabled = (bool) Setting::get('pppoe_enabled', 0);
        $pppoeSecrets = ($pppoeEnabled && $this->can('view_all', 'password'))
            ? \App\Models\PppoeSecret::whereIn('iface_id', $device->ifaces->pluck('id'))
                ->get()->keyBy('iface_id')
            : collect();

        return view('devices.show', [
            'device'             => $device,
            'canEdit'            => $this->can('edit_all'),
            'canDelete'          => $this->can('delete_all'),
            'canEditDevice'      => $this->can('edit_all'),
            'canViewLogin'       => $this->can('view_all', 'login'),
            'canViewPassword'    => $this->can('view_all', 'password'),
            'pppoeEnabled'       => $pppoeEnabled,
            'pppoeSecrets'       => $pppoeSecrets,
            'canManageEngineers' => $canManageEngineers,
            'canDeleteEngineer'  => $canDeleteEngineer,
            'engineerUsers'      => $engineerUsers,
            'canViewAll'         => $this->can('view_all'),
            'canViewUser'        => $this->aclCheck('view_all', 'Users_Controller', 'users'),
        ]);
    }

    // ── PPPoE přístupy (per iface) ──────────────────────────────────────────────

    /**
     * Vygeneruje (nebo dorovná) PPPoE credential pro rozhraní. Idempotentní —
     * existující heslo nemění. Vyžaduje právo editovat + vidět hesla; modul
     * musí být zapnutý (Setting pppoe_enabled). Viz onboarding návrh.
     */
    public function pppoeGenerate(int $ifaceId, \App\Services\PppoeSecretService $svc)
    {
        abort_unless((bool) Setting::get('pppoe_enabled', 0), 403, 'PPPoE modul není zapnutý.');
        abort_unless($this->can('edit_all') && $this->can('view_all', 'password'), 403);

        $iface  = $this->pppoeIface($ifaceId);
        $secret = $svc->ensureForIface($iface);

        return redirect()->route('devices.show', $iface->device_id)->with(
            $secret ? 'success' : 'error',
            $secret
                ? "PPPoE přístup vytvořen: {$secret->username}"
                : 'PPPoE přístup nelze vytvořit — rozhraní nemá přiřazeného člena.'
        );
    }

    /** Přegeneruje heslo (rotace) — username zůstává. Stejná autorizace jako generate. */
    public function pppoeRotate(int $ifaceId, \App\Services\PppoeSecretService $svc)
    {
        abort_unless((bool) Setting::get('pppoe_enabled', 0), 403, 'PPPoE modul není zapnutý.');
        abort_unless($this->can('edit_all') && $this->can('view_all', 'password'), 403);

        $iface  = $this->pppoeIface($ifaceId);
        $secret = $svc->rotateSecret($iface);

        return redirect()->route('devices.show', $iface->device_id)->with(
            $secret ? 'success' : 'error',
            $secret ? 'Heslo PPPoE bylo přegenerováno.' : 'Rotaci nelze provést.'
        );
    }

    /**
     * Dohledá iface pro PPPoE akci a ověří vlastnictví zařízení (uživatel ho smí
     * editovat). Autorizaci modulu + práva řeší volající metoda (kvůli statickému
     * ACL guardu musí být markery přímo v těle akce).
     */
    private function pppoeIface(int $ifaceId): Iface
    {
        $iface = Iface::with('device.user')->findOrFail($ifaceId);
        abort_if(!$iface->device, 404);
        abort_unless($this->can('view_all') || $this->isOwnUser($iface->device->user_id), 403);
        return $iface;
    }

    // ── Engineers ─────────────────────────────────────────────────────────────

    public function addEngineer(Request $request, int $deviceId)
    {
        abort_unless($this->can('new_all', 'engineer'), 403);

        $device = Device::findOrFail($deviceId);

        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $already = DeviceEngineer::where('device_id', $deviceId)
            ->where('user_id', $data['user_id'])->exists();

        if (!$already) {
            DeviceEngineer::create([
                'device_id' => $deviceId,
                'user_id'   => $data['user_id'],
            ]);
        }

        return redirect()->route('devices.show', $deviceId)
            ->with('success', 'Technik byl přidán.');
    }

    public function removeEngineer(int $deviceId, int $userId)
    {
        abort_unless($this->can('delete_all', 'engineer'), 403);

        // Hromadný delete přes query builder nespustí model events — logujeme ručně.
        DeviceEngineer::where('device_id', $deviceId)
            ->where('user_id', $userId)
            ->delete();

        \App\Services\AuditLogger::log('deleted', 'device_engineers', $deviceId, [
            'device_id' => $deviceId,
            'user_id'   => $userId,
        ], null);

        return redirect()->route('devices.show', $deviceId)
            ->with('success', 'Technik byl odebrán.');
    }

    public function createWithTemplate(Request $request, ?int $userId = null)
    {
        abort_unless($this->can('new_all'), 403);

        $user        = $userId ? User::findOrFail($userId) : null;
        $members     = $this->loadMembersForPicker();
        // Preselect: primárně z query ?member_id= (po reload form), fallback na member_id
        // hlavního usera v URL path (legacy "/devices/add/{userId}").
        $preselectedMemberId = $request->filled('member_id')
            ? (int) $request->query('member_id')
            : $user?->member_id;
        $deviceTypes = EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)->orderBy('value')->get();
        $rawTemplates = DeviceTemplate::with('enumType')->get();
        $subnets     = Subnet::orderBy('name')->get();

        // JSON-friendly list for JS filtering (id, name, enum_type_id)
        $templates = $rawTemplates->map(fn($t) => [
            'id'           => $t->id,
            'name'         => $t->name . ($t->enumType ? ' (' . $t->enumType->value . ')' : ''),
            'enum_type_id' => $t->enum_type_id,
        ]);

        // Pre-selected type from query string (preserved across template reload)
        $selectedTypeId = (int) $request->query('type_id') ?: null;

        // Server-side template rendering when template_id passed via GET
        $selectedTemplate  = null;
        $ifaceDefinitions  = [];
        if ($templateId = (int) $request->query('template_id')) {
            $selectedTemplate = $rawTemplates->find($templateId);
            if ($selectedTemplate) {
                $ifaceDefinitions = $selectedTemplate->getIfaceDefinitions();
            }
        }

        // Subnet data with free IPs for JS autocomplete
        $subnetData = Subnet::with('ipAddresses')->get()->map(function ($subnet) {
            $usedIps  = $subnet->ipAddresses->pluck('ip_address')->toArray();
            $network   = ip2long($subnet->network_address);
            $mask      = ip2long($subnet->netmask);
            $broadcast = $network | (~$mask & 0xFFFFFFFF);
            $freeIps   = [];
            for ($ip = $network + 1; $ip < $broadcast && count($freeIps) < 30; $ip++) {
                $ipStr = long2ip($ip);
                if (!in_array($ipStr, $usedIps)) {
                    $freeIps[] = $ipStr;
                }
            }
            return [
                'id'      => $subnet->id,
                'network' => $subnet->network_address,
                'mask'    => $subnet->netmask,
                'label'   => $subnet->label,
                'freeIps' => $freeIps,
            ];
        })->values()->toArray();

        $towns = DB::table('towns')->orderBy('town')->get(['id', 'town', 'zip_code']);

        return view('devices.add', compact(
            'user', 'members', 'preselectedMemberId', 'deviceTypes', 'templates',
            'subnets', 'selectedTemplate', 'ifaceDefinitions', 'selectedTypeId',
            'subnetData', 'towns'
        ));
    }

    public function createFromConnectionRequest(Request $request, int $crId)
    {
        abort_unless($this->can('new_all'), 403);

        $cr = ConnectionRequest::with(['member', 'deviceTemplate', 'deviceType'])->findOrFail($crId);

        // Find first user of the member
        $user = User::where('member_id', $cr->member_id)->orderBy('id')->first();
        $members     = $this->loadMembersForPicker();
        $preselectedMemberId = $cr->member_id;
        $deviceTypes = EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)->orderBy('value')->get();
        $rawTemplates = DeviceTemplate::with('enumType')->get();
        $subnets     = Subnet::orderBy('name')->get();

        $templates = $rawTemplates->map(fn($t) => [
            'id'           => $t->id,
            'name'         => $t->name . ($t->enumType ? ' (' . $t->enumType->value . ')' : ''),
            'enum_type_id' => $t->enum_type_id,
        ]);

        // Allow admin to override type/template via query params (e.g. after reloadWithTemplate())
        $selectedTypeId   = (int) $request->query('type_id', $cr->device_type_id) ?: $cr->device_type_id;
        $templateId       = (int) $request->query('template_id', $cr->device_template_id) ?: $cr->device_template_id;
        $selectedTemplate = $templateId ? $rawTemplates->find($templateId) : null;
        $ifaceDefinitions = $selectedTemplate ? $selectedTemplate->getIfaceDefinitions() : [];

        $subnetData = Subnet::with('ipAddresses')->get()->map(function ($subnet) {
            $usedIps   = $subnet->ipAddresses->pluck('ip_address')->toArray();
            $network   = ip2long($subnet->network_address);
            $mask      = ip2long($subnet->netmask);
            $broadcast = $network | (~$mask & 0xFFFFFFFF);
            $freeIps   = [];
            for ($ip = $network + 1; $ip < $broadcast && count($freeIps) < 30; $ip++) {
                $ipStr = long2ip($ip);
                if (!in_array($ipStr, $usedIps)) {
                    $freeIps[] = $ipStr;
                }
            }
            return [
                'id'      => $subnet->id,
                'network' => $subnet->network_address,
                'mask'    => $subnet->netmask,
                'label'   => $subnet->label,
                'freeIps' => $freeIps,
            ];
        })->values()->toArray();

        $towns = DB::table('towns')->orderBy('town')->get(['id', 'town', 'zip_code']);

        return view('devices.add', array_merge(compact(
            'user', 'members', 'preselectedMemberId', 'deviceTypes', 'templates',
            'subnets', 'selectedTemplate', 'ifaceDefinitions', 'selectedTypeId',
            'subnetData', 'towns'
        ), [
            'preselectedMac'      => $cr->mac_address,
            'preselectedIp'       => $cr->ip_address,
            'preselectedSubnetId' => $cr->subnet_id,
            'connectionRequestId' => $cr->id,
        ]));
    }

    public function storeWithTemplate(Request $request)
    {
        try {

        abort_unless($this->can('new_all'), 403);

        // Formulář posílá member_id; resolvneme na main user_id ještě před validací
        // a strčíme zpět do requestu jako 'user_id', aby zbytek pipeline (FormRequest,
        // validated array, downstream inserty) zůstal beze změny.
        if ($request->filled('member_id') && !$request->filled('user_id')) {
            $resolvedUserId = $this->resolveMainUserId((int) $request->input('member_id'));
            if (!$resolvedUserId) {
                return back()->withInput()
                    ->withErrors(['member_id' => 'Zvolený člen nemá hlavního uživatele.']);
            }
            $request->merge(['user_id' => $resolvedUserId]);
        }

        $baseRules = [
            'user_id'            => 'required|integer|exists:users,id',
            'name'               => 'required|string|max:255',
            'type'               => 'required|integer|exists:enum_types,id',
            'device_template_id' => 'nullable|integer|exists:device_templates,id',
            'buy_date'           => 'nullable|date',
            'comment'            => 'nullable|string|max:254',
            'town_id'            => 'nullable|integer|exists:towns,id',
            'street_id'          => 'nullable|integer|exists:streets,id',
            'street_number'      => 'nullable|string|max:50',
        ];

        // Build iface validation rules from template
        $ifaceRules = [];
        $ifaceDefs  = [];
        if ($templateId = (int) $request->input('device_template_id')) {
            $template = DeviceTemplate::findOrFail($templateId);
            $ifaceDefs = $template->getIfaceDefinitions();
            foreach ($ifaceDefs as $n => $def) {
                if (($def['count'] ?? 1) <= 0) continue;
                $ifaceRules["iface_name_{$n}"] = 'required|string|max:254';
                if ($def['has_mac']) {
                    $ifaceRules["iface_mac_{$n}"] = [
                        'nullable',
                        'regex:/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/',
                        'unique:ifaces,mac',
                    ];
                }
                if ($def['has_ip']) {
                    $ifaceRules["iface_ip_{$n}"]     = "nullable|ip|required_with:iface_subnet_{$n}";
                    $ifaceRules["iface_subnet_{$n}"] = "nullable|integer|exists:subnets,id|required_with:iface_ip_{$n}";
                }
            }
        }

        $validated = $request->validate(array_merge($baseRules, $ifaceRules), [
            'iface_mac_*.regex'  => 'MAC adresa musí být ve formátu aa:bb:cc:dd:ee:ff.',
            'iface_mac_*.unique' => 'Tato MAC adresa je již použita.',
        ]);

        // IP uniqueness check per subnet
        foreach ($ifaceDefs as $i => $def) {
            if (($def['count'] ?? 1) <= 0) continue;
            if (!($def['has_ip'] ?? false)) continue;
            $ip       = $request->input("iface_ip_{$i}");
            $subnetId = $request->input("iface_subnet_{$i}");
            if ($ip && $subnetId) {
                if (IpAddress::where('ip_address', $ip)->where('subnet_id', $subnetId)->exists()) {
                    return back()->withInput()->withErrors(["iface_ip_{$i}" => "IP adresa {$ip} je již použita v tomto subnetu."]);
                }
            }
        }

        // At least one has_mac iface must have MAC filled (if template has any)
        $hasMacDefs = array_filter($ifaceDefs, fn($d) => ($d['has_mac'] ?? false) && ($d['count'] ?? 1) > 0);
        if (!empty($hasMacDefs)) {
            $atLeastOneMac = false;
            foreach ($hasMacDefs as $n => $def) {
                if ($request->filled("iface_mac_{$n}")) {
                    $atLeastOneMac = true;
                    break;
                }
            }
            if (!$atLeastOneMac) {
                return back()->withInput()
                    ->withErrors(['iface_mac_0' => 'Alespoň jedno rozhraní musí mít vyplněnou MAC adresu.']);
            }
        }

        $deviceId = null;

        $touchedSubnetIds = [];

        $addressPointId = app(\App\Services\AddressResolverService::class)
            ->resolveAddressPoint(
                $validated['town_id'] ?? null,
                $validated['street_id'] ?? null,
                $validated['street_number'] ?? null
            );

        DB::transaction(function () use ($validated, $ifaceDefs, $request, $addressPointId, &$deviceId, &$touchedSubnetIds) {
            $memberId = User::find($validated['user_id'])?->member_id;

            $device = Device::create([
                'user_id'          => $validated['user_id'],
                'name'             => $validated['name'],
                'type'             => $validated['type'],
                'buy_date'         => $validated['buy_date'] ?? now()->toDateString(),
                'comment'          => $validated['comment'] ?? null,
                'address_point_id' => $addressPointId,
            ]);

            $deviceId = $device->id;

            foreach ($ifaceDefs as $n => $def) {
                if (($def['count'] ?? 1) <= 0) continue;
                $iface = Iface::create([
                    'device_id' => $device->id,
                    'type'      => $def['type'],
                    'name'      => $request->input("iface_name_{$n}"),
                    'mac'       => ($def['has_mac'] && $request->filled("iface_mac_{$n}"))
                                    ? $request->input("iface_mac_{$n}") : null,
                ]);

                if ($def['has_ip'] && $request->filled("iface_ip_{$n}")) {
                    $ipVal    = $request->input("iface_ip_{$n}");
                    $subnetId = (int) $request->input("iface_subnet_{$n}");
                    IpAddress::create([
                        'iface_id'   => $iface->id,
                        'subnet_id'  => $subnetId,
                        'ip_address' => $ipVal,
                        'member_id'  => $memberId,
                        'dhcp'       => 0,
                        'gateway'    => 0,
                        'service'    => 0,
                    ]);
                    $this->syncIp6Add($iface->id, $ipVal);
                    $touchedSubnetIds[$subnetId] = true;
                }
            }
        });

        // dhcp_expired=1 pro DHCP subnety, aby DHCP server přečetl nový lease
        foreach (array_keys($touchedSubnetIds) as $sid) {
            Subnet::find($sid)?->setExpired();
        }

        // Allowed subnets — auto-přidat každý subnet, do kterého padla nová IP
        $memberId = User::find($validated['user_id'])?->member_id;
        if ($memberId) {
            $sync = app(AllowedSubnetSyncService::class);
            foreach (array_keys($touchedSubnetIds) as $sid) {
                $sync->onIpAdded($memberId, (int) $sid);
            }

            // Pokud byl device přidán čekajícímu zákazníkovi (type=18), aktivovat
            // přesměrování okamžitě bez čekání na hodinový cron.
            if (!empty($touchedSubnetIds)) {
                app(\App\Services\PendingCustomerRedirectService::class)->refreshForMember((int) $memberId);
            }
        }

        // Approve connection request if device was created from one
        if ($crId = (int) $request->input('connection_request_id')) {
            $cr = ConnectionRequest::with('member')
                ->where('id', $crId)
                ->where('state', ConnectionRequest::STATE_UNDECIDED)
                ->first();
            if ($cr) {
                $cr->update([
                    'device_id'       => $deviceId,
                    'state'           => ConnectionRequest::STATE_APPROVED,
                    'decided_user_id' => auth()->id(),
                    'decided_at'      => now(),
                ]);
                // Message 13 — notify applicant of approval
                $this->sendMessageToMember(13, $cr->member_id, [
                    'member_name' => $cr->member?->name ?? '',
                    'comment'     => $cr->comment ?? '',
                ]);
            }
        }

        // PPPoE onboarding: u zákaznické registrace (z connection requestu) vygeneruj
        // PPPoE credential pro přípojné rozhraní (to, které dostalo statickou IP,
        // gateway=0). Jen když je modul zapnutý — během přechodu MAC/IP zůstává
        // výchozí. Nikdy nesmí shodit vytvoření zařízení (try/catch).
        if ($crId && Setting::get('pppoe_enabled', 0)) {
            try {
                $svc = app(\App\Services\PppoeSecretService::class);
                $cr  = ConnectionRequest::find($crId);
                $connIfaces = Iface::with('device.user')
                    ->where('device_id', $deviceId)
                    ->whereHas('ipAddresses', fn ($q) => $q->where('gateway', 0))
                    ->get();
                // První přípojné rozhraní zdědí credential z žádosti (instalátor ho
                // už zadal do CPE) — zachovat username/heslo. Další (kdyby jich bylo
                // víc) se dogenerují.
                $adopted = false;
                foreach ($connIfaces as $connIface) {
                    if (!$adopted && $cr && $cr->pppoe_username && $cr->pppoe_secret) {
                        $svc->adoptFromRequest($connIface, $cr->pppoe_username, $cr->pppoe_secret);
                        $adopted = true;
                    } else {
                        $svc->ensureForIface($connIface);
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('PPPoE credential při registraci selhal', [
                    'device' => $deviceId, 'error' => $e->getMessage(),
                ]);
            }
        }

        session()->flash('success', 'Zařízení bylo úspěšně přidáno.');
        return redirect()->route('devices.show', $deviceId);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Běžná chyba formuláře (např. prázdný název) — není to aplikační chyba,
            // Laravel ji sám zpracuje (redirect zpět s chybami). Nelogovat jako ERROR.
            throw $e;
        } catch (\Exception $e) {
            \Log::error('storeWithTemplate error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function create(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        // Pro zpětnou kompatibilitu URL i podporujeme ?user_id=, převedeme na member_id.
        $preselectedMemberId = null;
        if ($request->filled('member_id')) {
            $preselectedMemberId = (int) $request->query('member_id');
        } elseif ($request->filled('user_id')) {
            $preselectedMemberId = DB::table('users')->where('id', (int) $request->query('user_id'))->value('member_id');
        }
        $deviceTypes = EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)->orderBy('id')->pluck('value', 'id');
        $members     = $this->loadMembersForPicker();
        $towns       = DB::table('towns')->orderBy('town')->get(['id', 'town', 'zip_code']);

        return view('devices.create', [
            'deviceTypes'         => $deviceTypes,
            'members'             => $members,
            'towns'               => $towns,
            'preselectedMemberId' => $preselectedMemberId,
            'canEditLogin'        => $this->can('view_all', 'login'),
            'canEditPassword'     => $this->can('view_all', 'password'),
            'apTypeId'            => Device::AP_TYPE_ID,
        ]);
    }

    public function store(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $rules = [
            'member_id'       => 'required|integer|exists:members,id',
            'name'            => 'required|string|max:255',
            'type'            => 'required|integer|exists:enum_types,id',
            'trade_name'      => 'nullable|string|max:50',
            'operating_system'=> 'nullable|integer',
            'PPPoE_logging_in'=> 'nullable|integer',
            'price'           => 'nullable|numeric|min:0',
            'payment_rate'    => 'nullable|numeric|min:0',
            'buy_date'        => 'nullable|date',
            'comment'         => 'nullable|string|max:254',
            'town_id'         => 'nullable|integer|exists:towns,id',
            'street_id'       => 'nullable|integer|exists:streets,id',
            'street_number'   => 'nullable|string|max:50',
        ];

        // Login/heslo jsou citlivá — přijmout jen s jemným právem (stejně jako
        // update()). Formulář je i skrývá; bez práva se podvržený POST zahodí.
        if ($this->can('view_all', 'login')) {
            $rules['login'] = 'nullable|string|max:30';
        }
        if ($this->can('view_all', 'password')) {
            $rules['password'] = 'nullable|string|max:30';
            // WPA2 klíč AP: stejné jemné právo jako heslo. Délka dle WPA2 (8–63),
            // min 16 pro dostatečnou entropii (doporučení NIS2/ZKB pro sdílený klíč).
            $rules['wpa_key']  = 'nullable|string|min:22|max:63';
        }

        $data = $request->validate($rules);

        $data = $this->normalizeWpaKey($data);

        $userId = $this->resolveMainUserId((int) $data['member_id']);
        if (!$userId) {
            return back()->withInput()
                ->withErrors(['member_id' => 'Zvolený člen nemá hlavního uživatele.']);
        }
        $data['user_id'] = $userId;
        unset($data['member_id']);

        // Adresa umístění: z město/ulice/číslo najdi nebo vytvoř address_point.
        $data['address_point_id'] = app(\App\Services\AddressResolverService::class)
            ->resolveAddressPoint(
                $data['town_id'] ?? null,
                $data['street_id'] ?? null,
                $data['street_number'] ?? null
            );
        unset($data['town_id'], $data['street_id'], $data['street_number']);

        // Jako v Kohaně: pokud datum koupě není zadáno, ulož datum vytvoření.
        if (empty($data['buy_date'])) {
            $data['buy_date'] = now()->toDateString();
        }

        $device = Device::create($data);

        session()->flash('success', 'Zařízení bylo úspěšně přidáno.');
        return redirect()->route('devices.show', $device->id);
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $device = Device::with(['addressPoint.street', 'addressPoint.town'])->find($id);
        if (!$device) {
            abort(404);
        }

        $deviceTypes = EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)->orderBy('id')->pluck('value', 'id');
        $members     = $this->loadMembersForPicker();
        $towns       = DB::table('towns')->orderBy('town')->get(['id', 'town', 'zip_code']);
        // Preselect aktuálního člena přes user → member_id (všechny existující devices
        // koukají na main usera, takže member_id je vždy dostupný).
        $currentMemberId = DB::table('users')->where('id', $device->user_id)->value('member_id');

        // Aktuální adresa zařízení rozložená do polí (pro předvyplnění selectů).
        $ap = $device->addressPoint;
        $currentTownId      = $ap?->town_id;
        $currentStreetId    = $ap?->street_id;
        $currentStreetNumber = $ap?->street_number;

        return view('devices.edit', [
            'device'              => $device,
            'deviceTypes'         => $deviceTypes,
            'members'             => $members,
            'towns'               => $towns,
            'currentMemberId'     => $currentMemberId,
            'currentTownId'       => $currentTownId,
            'currentStreetId'     => $currentStreetId,
            'currentStreetNumber' => $currentStreetNumber,
            'canEditLogin'        => $this->can('view_all', 'login'),
            'canEditPassword'     => $this->can('view_all', 'password'),
            'apTypeId'            => Device::AP_TYPE_ID,
        ]);
    }

    public function update(Request $request, int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $device = Device::find($id);
        if (!$device) {
            abort(404);
        }

        $rules = [
            'member_id'       => 'required|integer|exists:members,id',
            'name'            => 'required|string|max:255',
            'type'            => 'required|integer|exists:enum_types,id',
            'trade_name'      => 'nullable|string|max:50',
            'operating_system'=> 'nullable|integer',
            'PPPoE_logging_in'=> 'nullable|integer',
            'price'           => 'nullable|numeric|min:0',
            'payment_rate'    => 'nullable|numeric|min:0',
            'buy_date'        => 'nullable|date',
            'comment'         => 'nullable|string|max:254',
            'town_id'         => 'nullable|integer|exists:towns,id',
            'street_id'       => 'nullable|integer|exists:streets,id',
            'street_number'   => 'nullable|string|max:50',
        ];

        if ($this->can('view_all', 'login')) {
            $rules['login'] = 'nullable|string|max:30';
        }

        if ($this->can('view_all', 'password')) {
            $rules['password'] = 'nullable|string|max:30';
            $rules['wpa_key']  = 'nullable|string|min:22|max:63';
        }

        $data = $request->validate($rules);

        $data = $this->normalizeWpaKey($data);

        $userId = $this->resolveMainUserId((int) $data['member_id']);
        if (!$userId) {
            return back()->withInput()
                ->withErrors(['member_id' => 'Zvolený člen nemá hlavního uživatele.']);
        }
        $data['user_id'] = $userId;
        unset($data['member_id']);

        // Adresa umístění: z město/ulice/číslo najdi nebo vytvoř address_point.
        $data['address_point_id'] = app(\App\Services\AddressResolverService::class)
            ->resolveAddressPoint(
                $data['town_id'] ?? null,
                $data['street_id'] ?? null,
                $data['street_number'] ?? null
            );
        unset($data['town_id'], $data['street_id'], $data['street_number']);

        $device->update($data);

        session()->flash('success', 'Zařízení bylo úspěšně upraveno.');
        return redirect()->route('devices.show', $id);
    }

    public function destroy(int $id)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $device = Device::with('ifaces.ipAddresses', 'user')->findOrFail($id);
        $userId   = $device->user_id;
        $memberId = $device->user?->member_id;

        // Posbíráme subnety dotčené smazáním (aby ->ipAddresses()->delete()
        // jako mass-delete bez Eloquent eventů nezamlčel dhcp_expired flag).
        $touchedSubnetIds = [];
        $removedIps = [];
        foreach ($device->ifaces as $iface) {
            foreach ($iface->ipAddresses as $ip) {
                if ($ip->subnet_id) $touchedSubnetIds[$ip->subnet_id] = true;
                if ($ip->ip_address) $removedIps[] = $ip->ip_address;
            }
        }

        $deviceName = $device->name;
        DB::transaction(function () use ($device) {
            foreach ($device->ifaces as $iface) {
                $iface->ip6Addresses()->delete();
                $iface->ipAddresses()->delete();
            }
            $device->ifaces()->delete();
            $device->delete();
        });

        // Trait zaloguje smazání device-centricky, ale rozhraní/IP se mažou hromadně
        // (bez eventů) a smazané zařízení už nedohledáme přes živé ID. Proto navíc
        // member-keyed záznam s IP adresami — aby bylo smazání vidět na kartě člena.
        if ($memberId) {
            \App\Services\AuditLogger::log('device_removed', 'members', $memberId, [
                'device_id'   => $id,
                'device_name' => $deviceName,
                'ip_adresy'   => $removedIps,
            ], null);
        }

        foreach (array_keys($touchedSubnetIds) as $sid) {
            Subnet::find($sid)?->setExpired();
        }

        // Allowed subnets — pokud člen v subnetu nemá už žádnou jinou IP,
        // subnet odebereme z allowed (sync sám zkontroluje, zda zbyla jiná).
        if ($memberId) {
            $sync = app(AllowedSubnetSyncService::class);
            foreach (array_keys($touchedSubnetIds) as $sid) {
                $sync->onIpRemoved($memberId, (int) $sid);
            }
        }

        return redirect()->route('devices.by_user', $userId)
            ->with('success', 'Zařízení bylo smazáno.');
    }

    // ── DHCP Servers overview ─────────────────────────────────────────────────

    public function dhcpServers()
    {
        abort_unless($this->aclCheck('view_all', 'Devices_Controller', 'devices'), 403);

        // s.dhcp = 1 filtruje vypnuté subnety — bez toho zombie dhcp_expired=1 na
        // subnetech, kde admin DHCP vypnul, drží zařízení trvale ve stavu 'Změněno'
        // (export nikdy nezresetuje flag u subnetů s subnet.dhcp=0).
        $devices = DB::select("
            SELECT DISTINCT d.id, d.name, d.access_time,
                GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS subnets,
                MAX(s.dhcp_expired) AS has_expired
            FROM devices d
            JOIN ifaces i ON i.device_id = d.id
            JOIN ip_addresses ip ON ip.iface_id = i.id
            JOIN subnets s ON s.id = ip.subnet_id
            WHERE ip.gateway = 1 AND ip.dhcp = 1 AND s.dhcp = 1
            GROUP BY d.id, d.name, d.access_time
            ORDER BY d.access_time ASC
        ");

        $devices = array_map(function ($device) {
            $device->error = $device->access_time &&
                $device->access_time !== '0000-00-00 00:00:00' &&
                Carbon::parse($device->access_time)->diffInMinutes(now()) > 5;
            return $device;
        }, $devices);

        $token = Setting::get('dhcp_api_token');

        return view('devices.dhcp_servers', compact('devices', 'token'));
    }

    // ── DHCP Export ───────────────────────────────────────────────────────────

    public function export(Request $request, int $id, string $format)
    {
        $allowed = ['mikrotik-ip-dhcp-server', 'mikrotik-ip-dhcp-server-lease'];
        if (!in_array($format, $allowed)) {
            abort(404);
        }

        // Čas zachytíme PŘED načtením stavu subnetů. Jako high-water-mark klienta
        // pak uložíme právě tenhle okamžik — kdyby změna přišla během exportu
        // (po tomto čtení), bude mít dhcp_changed_at > exportStartedAt a příští
        // fetch ji spolehlivě chytí (žádná ztracená změna).
        $exportStartedAt = now();

        $device = Device::with(['ifaces.ipAddresses.subnet'])->find($id);
        if (!$device) {
            abort(request()->ip() ? 404 : 404);
        }

        // Auth: DHCP servery se ověřují dhcp_api_tokenem, jinak přihlášený uživatel
        // s ACL view_all#export. Legacy „volání z vlastní IP zařízení" (fromDevice)
        // bylo odstraněno (audit 2026-08-18): DHCP servery už jezdí přes token a
        // IP-based větev jen otevírala cross-member únik (buildDhcpServers vydává
        // MAC + jméno člena sousedů na subnetu komukoliv, kdo zavolá z IP zařízení).
        $token      = $request->input('token') ?? $request->bearerToken();
        $validToken = Setting::get('dhcp_api_token');
        $tokenValid = $token && $validToken && hash_equals($validToken, $token);

        if (!$tokenValid) {
            if (auth()->guest()) {
                abort(403);
            }
            abort_unless($this->aclCheck('view_all', 'Devices_Controller', 'export'), 403);
        }

        // Update access_time on every authorized call
        if ($tokenValid) {
            $device->update(['access_time' => now()]);
        }

        // DHCP change detection — skip if forced. Dva režimy:
        //  - ?client=... : per-konzument (timestamp vs high-water-mark) → víc DHCP
        //    serverů čte stejné subnety a každý vidí každou změnu právě jednou.
        //  - bez client   : legacy sdílený boolean dhcp_expired (jeden konzument).
        $forced = $request->boolean('forced');
        $client = $this->normalizeDhcpClient($request->input('client'));

        if (!$forced && $tokenValid) {
            if ($client !== null) {
                $latestChange = null;
                foreach ($device->ifaces as $iface) {
                    foreach ($iface->ipAddresses as $ip) {
                        if ($ip->subnet && $ip->subnet->dhcp && $ip->subnet->dhcp_changed_at) {
                            $ts = $ip->subnet->dhcp_changed_at;
                            if ($latestChange === null || $ts->gt($latestChange)) {
                                $latestChange = $ts;
                            }
                        }
                    }
                }
                $seenRaw = DB::table('dhcp_export_state')->where('client', $client)->value('exported_at');
                $seen    = $seenRaw ? \Carbon\Carbon::parse($seenRaw) : null;
                // 204 jen když klient už stáhl a od té doby se nic nezměnilo.
                // Striktní `<` (ne `<=`): při shodě raději re-export — import je
                // idempotentní, kdežto vynechání by znamenalo ztracenou změnu.
                if ($seen !== null && ($latestChange === null || $latestChange->lt($seen))) {
                    return response('', 204);
                }
            } else {
                $hasExpired = false;
                foreach ($device->ifaces as $iface) {
                    foreach ($iface->ipAddresses as $ip) {
                        if ($ip->subnet && $ip->subnet->dhcp && $ip->subnet->dhcp_expired) {
                            $hasExpired = true;
                            break 2;
                        }
                    }
                }
                if (!$hasExpired) {
                    return response('', 204);
                }
            }
        }

        // Build DHCP server data
        $dhcpServers = $this->buildDhcpServers($device);

        // Relay režim se zapne, když je zařízení v dhcp_relay_map. role=static →
        // druhý (static-only) server v páru; jinak primary.
        $relayInterface = $this->relayInterfaceForDevice($id);
        $role           = $request->input('role') === 'static' ? 'static' : 'primary';

        $text = $format === 'mikrotik-ip-dhcp-server'
            ? $this->renderMikrotikFull($dhcpServers, $relayInterface, $role)
            : $this->renderMikrotikLeaseOnly($dhcpServers);

        // Zapiš, že tento konzument je aktuální. Per-client posune jen jeho
        // high-water-mark (ostatní DHCP servery změnu pořád uvidí); legacy shodí
        // sdílený flag jako dosud.
        if ($tokenValid) {
            if ($client !== null) {
                DB::table('dhcp_export_state')->updateOrInsert(
                    ['client' => $client],
                    // formát s .u — query builder jinak Carbon uloží jen s vteřinovou
                    // přesností a mikrosekundová detekce by ztratila smysl.
                    ['exported_at' => $exportStartedAt->format('Y-m-d H:i:s.u')]
                );
            } else {
                foreach ($device->ifaces as $iface) {
                    foreach ($iface->ipAddresses as $ip) {
                        if ($ip->subnet && $ip->subnet->dhcp) {
                            $ip->subnet->setNotExpired();
                        }
                    }
                }
            }
        }

        return response($text, 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * CSV inventář VŠECH zařízení — vše jako na detailu zařízení (info, technici,
     * rozhraní s MAC/IPv4/IPv6), BEZ PPPoE sekce. Credentialy (login/heslo/wpa2)
     * jen s příslušným právem (stejně jako detail). Streamované + chunk kvůli
     * tisícům zařízení (bounded paměť). Oddělovač ';' + UTF-8 BOM (Excel/CZ).
     */
    public function inventoryCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($this->can('view_all'), 403);
        $canLogin   = $this->can('view_all', 'login');
        $canPass    = $this->can('view_all', 'password');
        $typeLabels = Iface::typeLabels();

        $columns = [
            'ID', 'Název', 'Typ', 'Obchodní název', 'Uživatel', 'Člen', 'Adresa umístění',
            'Operační systém', 'Login', 'Heslo', 'WPA2 klíč', 'Poslední přístup',
            'Cena', 'Měsíční splátka', 'Datum koupě', 'Komentář', 'Technici zařízení',
            'Rozhraní (detail)', 'MAC adresy', 'IPv4 adresy', 'IPv6 adresy',
        ];
        $filename = 'zarizeni-inventar-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($columns, $canLogin, $canPass, $typeLabels) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
            fputcsv($out, $columns, ';');

            Device::with([
                'enumType', 'user.member',
                'addressPoint.street', 'addressPoint.town',
                'deviceEngineers.user',
                'ifaces.ipAddresses', 'ifaces.ip6Addresses',
            ])->orderBy('id')->chunk(200, function ($devices) use ($out, $canLogin, $canPass, $typeLabels) {
                foreach ($devices as $d) {
                    $ap = $d->addressPoint;
                    $addr = $ap
                        ? trim(trim(($ap->street->street ?? '') . ' ' . ($ap->street_number ?? ''))
                            . ', ' . trim(($ap->town->town ?? '') . ' ' . ($ap->town->zip_code ?? '')), ' ,')
                        : '';

                    $techs = $d->deviceEngineers->map(function ($e) {
                        $u = $e->user;
                        if (!$u) return '#' . $e->user_id;
                        $jm = trim(($u->name ?? '') . ' ' . ($u->surname ?? ''));
                        return $u->login . ($jm !== '' ? " ({$jm})" : '');
                    })->implode('; ');

                    $ifaceParts = []; $macs = []; $v4 = []; $v6 = [];
                    foreach ($d->ifaces as $if) {
                        $ips = $if->ipAddresses->pluck('ip_address')->all();
                        $p6  = $if->ip6Addresses->pluck('ip_address')->all();
                        if ($if->mac) $macs[] = $if->mac;
                        $v4 = array_merge($v4, $ips);
                        $v6 = array_merge($v6, $p6);
                        $ifaceParts[] = ($if->name ?? '?')
                            . '(' . ($typeLabels[$if->type] ?? $if->type) . ')'
                            . ' MAC=' . ($if->mac ?: '-')
                            . ' IPv4=[' . implode(',', $ips) . ']'
                            . ' IPv6=[' . implode(',', $p6) . ']';
                    }

                    // Credentialy jsou šifrované at-rest — dešifrování obalíme, ať
                    // jeden vadný řádek neshodí celý export.
                    $login = $canLogin ? ($d->login ?? '') : '';
                    $pass = ''; $wpa = '';
                    if ($canPass) {
                        try { $pass = (string) ($d->password ?? ''); } catch (\Throwable $e) { $pass = '(nešlo dešifrovat)'; }
                        try { $wpa  = (string) ($d->wpa_key ?? ''); } catch (\Throwable $e) { $wpa = '(nešlo dešifrovat)'; }
                    }

                    fputcsv($out, [
                        $d->id,
                        $d->name,
                        $d->enumType->value ?? $d->type,
                        $d->trade_name ?? '',
                        $d->user?->full_name ?? '',
                        $d->user?->member?->name ?? '',
                        $addr,
                        $d->operating_system ?? '',
                        $login,
                        $pass,
                        $wpa,
                        $d->access_time ?? '',
                        $d->price ?? '',
                        $d->payment_rate ?? '',
                        ($d->buy_date && $d->buy_date !== '0000-00-00') ? $d->buy_date : '',
                        $d->comment ?? '',
                        $techs,
                        implode(' || ', $ifaceParts),
                        implode('; ', $macs),
                        implode('; ', $v4),
                        implode('; ', $v6),
                    ], ';');
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function buildDhcpServers(Device $device): array
    {
        // Admin v UI typicky odděluje DNS servery Enterem (LF/CRLF), ale legacy
        // data z Kohana migrace občas obsahují literální `\n` (backslash-n).
        // Sjednotíme separátor a po splitu validujeme IPv4 — kdyby v hodnotě
        // bylo cokoliv jiného, do Mikrotik exportu prosakovala garbage jako
        // `dns-server=10.133.37.3710.133.37.38` (chybějící čárka, nebo dvojice
        // IP slepená do sebe).
        $dnsRaw     = (string) Setting::get('dns_servers', '');
        $dnsRaw     = str_replace('\\n', "\n", $dnsRaw);
        $dnsServers = array_values(array_filter(
            array_map('trim', preg_split('/[,;\s|]+/', $dnsRaw)),
            fn($v) => $v !== '' && filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ));

        $servers = [];

        // IP čekajících žádostí o připojení (state=UNDECIDED) — se zapnutým PPPoE
        // je RADIUS servíruje jako Framed-IP z žádosti UŽ PŘED schválením, takže
        // je musí pool vyříznout stejně jako registrované statiky (jinak by je
        // přidělil dalšímu bootstrap klientovi = konflikt). long2ip klíče.
        $pendingReqLongs = Setting::get('pppoe_enabled', 0)
            ? DB::table('connection_requests')
                ->where('state', ConnectionRequest::STATE_UNDECIDED)
                ->whereNotNull('ip_address')
                ->pluck('ip_address')
                ->map(fn ($ip) => ip2long($ip))
                ->filter(fn ($l) => $l !== false)
                ->all()
            : [];

        foreach ($device->ifaces as $iface) {
            foreach ($iface->ipAddresses as $ip) {
                \Log::debug('DHCP export ip check', [
                    'ip'      => $ip->ip_address,
                    'gateway' => $ip->gateway,
                    'subnet'  => $ip->subnet?->network_address,
                    'dhcp'    => $ip->subnet?->dhcp,
                ]);
                if (!$ip->gateway || !$ip->subnet) continue;
                $subnet = $ip->subnet;
                if (!$subnet->dhcp) continue;

                $network   = ip2long($subnet->network_address);
                $mask      = ip2long($subnet->netmask);
                $broadcast = long2ip($network | (~$mask & 0xFFFFFFFF));
                $cidrBits  = 32 - (int) log(~$mask & 0xFFFFFFFF, 2) - 1;
                // fix: count bits properly
                $cidrBits  = substr_count(sprintf('%032b', $mask & 0xFFFFFFFF), '1');
                // Fragmentovaný pool = univerzum (gateway+1 .. broadcast-1) MÍNUS
                // všechny FreenetIS-alokované IP (iface_id) v subnetu. Důvod: PPPoE
                // `/ip pool` nezná statické DHCP leasy ani RADIUS Framed-IP → kdyby
                // registrovaná statika zůstala v ranges, PPPoE bootstrap (admin) by
                // ji přidělil znovu = adresní/routing konflikt na BRASu. Pro DHCP je
                // fragmentace ekvivalentní (statiku i tak chrání MAC lease), takže
                // jeden sdílený pool obslouží DHCP i PPPoE. Viz onboarding návrh.
                $poolStart = ip2long($ip->ip_address) + 1;
                $poolEnd   = ip2long($broadcast) - 1;
                $excluded  = [];
                foreach ($subnet->ipAddresses as $alloc) {
                    if (!$alloc->iface_id) continue;
                    $l = ip2long($alloc->ip_address);
                    if ($l !== false && $l >= $poolStart && $l <= $poolEnd) {
                        $excluded[$l] = true;
                    }
                }
                // + IP čekajících žádostí spadající do tohoto poolu (viz výše).
                foreach ($pendingReqLongs as $l) {
                    if ($l >= $poolStart && $l <= $poolEnd) {
                        $excluded[$l] = true;
                    }
                }
                $ranges     = $this->fragmentPoolRanges($poolStart, $poolEnd, array_keys($excluded));
                $rangeStart = long2ip($poolStart);
                $rangeEnd   = long2ip($poolEnd);

                $dnsList = $dnsServers;
                if ($subnet->dns ?? false) {
                    array_unshift($dnsList, $ip->ip_address);
                }

                $hosts = [];
                foreach ($subnet->ipAddresses as $hostIp) {
                    if ($hostIp->ip_address === $ip->ip_address || !$hostIp->iface_id) continue;
                    $mac = $hostIp->iface?->mac ?? '';
                    if (!$mac || !preg_match('/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $mac)) continue;
                    $deviceName  = $hostIp->iface?->device?->name ?? '';
                    $memberId    = $hostIp->iface?->device?->user?->member_id ?? '';
                    $memberName  = $hostIp->iface?->device?->user?->member?->name ?? '';
                    $hosts[$mac] = [
                        'ip_address' => $hostIp->ip_address,
                        'mac'        => $mac,
                        'server'     => $this->ascii($subnet->name),
                        'comment'    => "ID {$memberId} - {$memberName} - {$deviceName}",
                    ];
                }
                ksort($hosts);

                $servers[] = [
                    'name'        => $this->ascii($subnet->name),
                    'cidr'        => $subnet->network_address . '/' . $cidrBits,
                    'network'     => $subnet->network_address,
                    'netmask'     => $subnet->netmask,
                    'gateway'     => $ip->ip_address,
                    'interface'   => $iface->name,
                    'range_start' => $rangeStart,
                    'range_end'   => $rangeEnd,
                    'ranges'      => $ranges,
                    'dns_servers' => $dnsList,
                    'hosts'       => array_values($hosts),
                ];
            }
        }
        return $servers;
    }

    /**
     * @param ?string $relayInterface  Když je zadané (zařízení je v dhcp_relay_map),
     *                                  export běží v relay režimu: interface=<toto>
     *                                  + relay=<gateway subnetu> místo lokálního iface.
     * @param string  $role            'primary' = plný server (authoritative=yes,
     *                                  vlastní pool); 'static' = druhý server v páru
     *                                  (authoritative=after-10sec-delay, static-only).
     */
    /**
     * Poskládá pool ranges z intervalu [$startLong, $endLong] s vyříznutými
     * adresami $excludedLongs (fragmentovaný pool = subnet minus registrované
     * statiky). Vrací pole "start-end" řetězců — pro RouterOS `ranges=a-b,c-d`.
     * Prázdné pole = celý interval je alokovaný (žádná volná adresa).
     */
    private function fragmentPoolRanges(int $startLong, int $endLong, array $excludedLongs): array
    {
        if ($startLong > $endLong) {
            return [];
        }
        $ex = array_filter($excludedLongs, fn ($l) => $l >= $startLong && $l <= $endLong);
        $ex = array_values(array_unique($ex));
        sort($ex);

        $ranges = [];
        $cur = $startLong;
        foreach ($ex as $e) {
            if ($e > $cur) {
                $ranges[] = long2ip($cur) . '-' . long2ip($e - 1);
            }
            $cur = $e + 1;
        }
        if ($cur <= $endLong) {
            $ranges[] = long2ip($cur) . '-' . long2ip($endLong);
        }
        return $ranges;
    }

    private function renderMikrotikFull(array $servers, ?string $relayInterface = null, string $role = 'primary'): string
    {
        // Setting::get vrací uloženou hodnotu — když admin v UI uloží prázdné
        // pole, vrátí '' a default '10800' v get() se neuplatní. Cast na int
        // pak udělá 0 → lease-time=00:00:00. 3 h fallback bereme i pro 0.
        $leaseSeconds = (int) Setting::get('dhcp_lease_time', '10800');
        if ($leaseSeconds <= 0) $leaseSeconds = 10800;
        $leaseTime    = sprintf('%02d:%02d:%02d', intdiv($leaseSeconds, 3600), intdiv($leaseSeconds % 3600, 60), $leaseSeconds % 60);

        $isStatic = $relayInterface !== null && $role === 'static';

        $out = '';
        // Pool NEmazat přes `remove [find]` — smazal by i pool, na který se PPP
        // profil váže interním ID (remote-address=*5E). Po remove+add dostane
        // pool nové ID → PPP profil ztratí pool (sdílený DHCP+PPPoE pool). Upsert
        // podle JMÉNA: existující přepíšeme v místě (`set` → ID zůstane → PPP
        // binding drží), chybějící přidáme. DHCP-server pool referuje jménem,
        // tomu ID nevadí. Trade-off: pooly zrušených subnetů se už neuklidí
        // hromadně (osiřelý pool bez referujícího dhcp-serveru/profilu je inertní).
        foreach ($servers as $s) {
            // Fragmentovaný pool (víc rozsahů s dírami po registrovaných statikách).
            // Fallback na souvislý rozsah jen když je pool celý zaplněný (prázdné
            // $ranges) — to udrží DHCP funkční (statiku i tak chrání MAC lease).
            $rangesStr = !empty($s['ranges'])
                ? implode(',', $s['ranges'])
                : "{$s['range_start']}-{$s['range_end']}";
            $nm = $s['name'];
            $out .= ":if ([:len [/ip pool find name=\"{$nm}\"]]=0)"
                  . " do={/ip pool add name=\"{$nm}\" ranges={$rangesStr}}"
                  . " else={/ip pool set [/ip pool find name=\"{$nm}\"] ranges={$rangesStr}}\r\n";
        }
        $out .= "/ip dhcp-server\r\nremove [find]\r\n";
        foreach ($servers as $s) {
            if ($relayInterface !== null) {
                // Relay režim: matchujeme podle relay=<gateway> (giaddr), interface
                // je společné relay rozhraní (např. vlan1010), ne lokální iface.
                $iface = $this->ascii($relayInterface);
                if ($isStatic) {
                    $out .= "add name=\"{$s['name']}\" authoritative=after-10sec-delay bootp-support=static disabled=no interface=\"{$iface}\" relay={$s['gateway']} lease-time={$leaseTime} address-pool=static-only\r\n";
                } else {
                    $out .= "add name=\"{$s['name']}\" address-pool=\"{$s['name']}\" authoritative=yes bootp-support=static disabled=no interface=\"{$iface}\" relay={$s['gateway']} lease-time={$leaseTime}\r\n";
                }
            } else {
                $out .= "add name=\"{$s['name']}\" address-pool=\"{$s['name']}\" authoritative=after-2sec-delay bootp-support=static disabled=no interface=\"{$s['interface']}\" lease-time={$leaseTime}\r\n";
            }
        }
        $out .= "/ip dhcp-server network\r\nremove [find]\r\n";
        foreach ($servers as $s) {
            $dns = implode(',', $s['dns_servers']);
            $out .= "add address={$s['cidr']} dhcp-option=\"\" dns-server={$dns} gateway={$s['gateway']} ntp-server=\"\" wins-server=\"\"\r\n";
        }
        $out .= "/ip dhcp-server lease\r\nremove [find]\r\n";
        foreach ($servers as $s) {
            foreach ($s['hosts'] as $h) {
                $out .= "add address={$h['ip_address']} disabled=no mac-address={$h['mac']} server=\"{$h['server']}\" comment=\"{$h['comment']}\"\r\n";
            }
        }

        // PPP profil + pppoe-server (jen se zapnutým PPPoE, ne v relay režimu —
        // relay MK není zákaznická brána). Add-if-missing: existující (ručně
        // laděné) profily/servery NEpřepisujeme, jen doplníme chybějící →
        // žádné mazání, žádné clobbernutí. RADIUS klient + `/ppp aaa use-radius`
        // zůstávají ruční infra per-MK (mimo rozsah exportu); registrovaným
        // zákazníkům dává IP RADIUS Framed-IP, remote-address=pool je jen
        // bootstrap fallback (admin/admin → captive). Profil: name/remote-address
        // = název subnetu (=sdílený pool), local-address = brána, dns z nastavení,
        // change-tcp-mss=yes (PPPoE 1492 → MSS clamp).
        if (Setting::get('pppoe_enabled', 0) && $relayInterface === null) {
            $serviceName = $this->ascii((string) Setting::get('pppoe_service_name', 'service1'));
            $seenIfaces  = [];
            foreach ($servers as $s) {
                $nm  = $s['name'];
                $dns = implode(',', $s['dns_servers']);
                $out .= ":if ([:len [/ppp profile find name=\"{$nm}\"]]=0)"
                      . " do={/ppp profile add name=\"{$nm}\" local-address={$s['gateway']} remote-address=\"{$nm}\" dns-server={$dns} use-ipv6=yes change-tcp-mss=yes}\r\n";

                // pppoe-server: max 1 na rozhraní (víc subnetů na jednom iface →
                // první profil jako default; RADIUS stejně přepíše IP per-user).
                $iface = $s['interface'];
                if (isset($seenIfaces[$iface])) continue;
                $seenIfaces[$iface] = true;
                $out .= ":if ([:len [/interface pppoe-server server find interface=\"{$iface}\"]]=0)"
                      . " do={/interface pppoe-server server add service-name=\"{$serviceName}\" interface=\"{$iface}\" default-profile=\"{$nm}\" disabled=no one-session-per-host=yes authentication=pap,chap,mschap2}\r\n";
            }
        }

        return $out;
    }

    private function renderMikrotikLeaseOnly(array $servers): string
    {
        $out = "/ip dhcp-server lease\r\n";
        foreach ($servers as $s) {
            $out .= "remove [find server=\"{$s['name']}\"]\r\n";
            foreach ($s['hosts'] as $h) {
                $out .= "add address={$h['ip_address']} disabled=no mac-address={$h['mac']} server=\"{$h['server']}\" comment=\"{$h['comment']}\"\r\n";
            }
        }
        return $out;
    }

    private function ascii(string $s): string
    {
        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    }

    /**
     * Relay rozhraní pro dané DHCP zařízení z nastavení dhcp_relay_map (řádky
     * `ID=rozhraní`). Null = zařízení v mapě není → klasický interface-based export.
     */
    private function relayInterfaceForDevice(int $deviceId): ?string
    {
        $raw = (string) Setting::get('dhcp_relay_map', '');
        if ($raw === '') {
            return null;
        }
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$id, $iface] = array_map('trim', explode('=', $line, 2));
            if ($id === (string) $deviceId && $iface !== '') {
                return $iface;
            }
        }
        return null;
    }

    /**
     * Sanitizace identifikátoru konzumenta z ?client=... — omezíme na bezpečné
     * znaky a délku, ať se hodnota bezpečně použije jako klíč v dhcp_export_state.
     */
    private function normalizeDhcpClient($raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $raw = preg_replace('/[^A-Za-z0-9_.\-]/', '', trim($raw));
        return ($raw === null || $raw === '') ? null : substr($raw, 0, 64);
    }
}
