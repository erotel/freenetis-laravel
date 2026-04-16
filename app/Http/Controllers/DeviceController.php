<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SyncsIp6Address;
use App\Models\ConnectionRequest;
use App\Models\Device;
use App\Models\DeviceEngineer;
use App\Models\DeviceTemplate;
use App\Models\EnumType;
use App\Models\Iface;
use App\Models\IpAddress;
use App\Models\Subnet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    use SyncsIp6Address;
    private const ACL_SECTION = 'Devices_Controller';
    private const ACL_VALUE   = 'devices';

    private function can(string $action, string $value = self::ACL_VALUE): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, $value);
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
            $query->where('name', 'like', "%{$search}%");
        }

        $devices = $query->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();

        return view('devices.index', [
            'devices'   => $devices,
            'sort'      => $sort,
            'dir'       => $dir,
            'perPage'   => $perPage,
            'search'    => $search,
            'canNew'    => $this->can('new_all'),
            'canEdit'   => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
        ]);
    }

    public function showByUser(int $userId)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $user = User::find($userId);
        if (!$user) {
            abort(404);
        }

        $devices = Device::with('enumType')
            ->withCount('ifaces')
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get();

        return view('devices.show_by_user', [
            'user'      => $user,
            'devices'   => $devices,
            'canNew'    => $this->can('new_all'),
            'canEdit'   => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
        ]);
    }

    public function show(int $id)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

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

        $canManageEngineers = $this->can('new_all', 'engineer');
        $canDeleteEngineer  = $this->can('delete_all', 'engineer');

        $assignedEngineerIds = $device->deviceEngineers->pluck('user_id')->all();
        $engineerUsers = $canManageEngineers
            ? User::orderBy('surname')->orderBy('name')
                ->whereNotIn('id', $assignedEngineerIds)
                ->get(['id', 'login', 'name', 'surname'])
            : collect();

        return view('devices.show', [
            'device'             => $device,
            'canEdit'            => $this->can('edit_all'),
            'canDelete'          => $this->can('delete_all'),
            'canEditDevice'      => $this->can('edit_all'),
            'canViewLogin'       => $this->can('view_all', 'login'),
            'canViewPassword'    => $this->can('view_all', 'password'),
            'canManageEngineers' => $canManageEngineers,
            'canDeleteEngineer'  => $canDeleteEngineer,
            'engineerUsers'      => $engineerUsers,
        ]);
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

        DeviceEngineer::where('device_id', $deviceId)
            ->where('user_id', $userId)
            ->delete();

        return redirect()->route('devices.show', $deviceId)
            ->with('success', 'Technik byl odebrán.');
    }

    public function createWithTemplate(Request $request, int $userId = null)
    {
        abort_unless($this->can('new_all'), 403);

        $user        = $userId ? User::findOrFail($userId) : null;
        $users       = User::orderBy('surname')->orderBy('name')->get();
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

        return view('devices.add', compact(
            'user', 'users', 'deviceTypes', 'templates',
            'subnets', 'selectedTemplate', 'ifaceDefinitions', 'selectedTypeId',
            'subnetData'
        ));
    }

    public function createFromConnectionRequest(Request $request, int $crId)
    {
        abort_unless($this->can('new_all'), 403);

        $cr = ConnectionRequest::with(['member', 'deviceTemplate', 'deviceType'])->findOrFail($crId);

        // Find first user of the member
        $user = User::where('member_id', $cr->member_id)->orderBy('id')->first();
        $users       = User::orderBy('surname')->orderBy('name')->get();
        $deviceTypes = EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)->orderBy('value')->get();
        $rawTemplates = DeviceTemplate::with('enumType')->get();
        $subnets     = Subnet::orderBy('name')->get();

        $templates = $rawTemplates->map(fn($t) => [
            'id'           => $t->id,
            'name'         => $t->name . ($t->enumType ? ' (' . $t->enumType->value . ')' : ''),
            'enum_type_id' => $t->enum_type_id,
        ]);

        $selectedTypeId   = $cr->device_type_id;
        $selectedTemplate = $cr->device_template_id ? $rawTemplates->find($cr->device_template_id) : null;
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

        return view('devices.add', array_merge(compact(
            'user', 'users', 'deviceTypes', 'templates',
            'subnets', 'selectedTemplate', 'ifaceDefinitions', 'selectedTypeId',
            'subnetData'
        ), [
            'preselectedUserId'   => $user?->id,
            'preselectedMac'      => $cr->mac_address,
            'preselectedIp'       => $cr->ip_address,
            'preselectedSubnetId' => $cr->subnet_id,
            'connectionRequestId' => $cr->id,
        ]));
    }

    public function storeWithTemplate(Request $request)
    {
        \Log::info('storeWithTemplate called', ['all' => $request->except(['_token'])]);

        try {

        abort_unless($this->can('new_all'), 403);

        $baseRules = [
            'user_id'            => 'required|integer|exists:users,id',
            'name'               => 'required|string|max:255',
            'type'               => 'required|integer|exists:enum_types,id',
            'device_template_id' => 'nullable|integer|exists:device_templates,id',
            'buy_date'           => 'nullable|date',
            'comment'            => 'nullable|string|max:254',
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

        DB::transaction(function () use ($validated, $ifaceDefs, $request, &$deviceId) {
            $memberId = User::find($validated['user_id'])?->member_id;

            $device = Device::create([
                'user_id'  => $validated['user_id'],
                'name'     => $validated['name'],
                'type'     => $validated['type'],
                'buy_date' => $validated['buy_date'] ?? null,
                'comment'  => $validated['comment'] ?? null,
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
                    $ipVal = $request->input("iface_ip_{$n}");
                    IpAddress::create([
                        'iface_id'   => $iface->id,
                        'subnet_id'  => $request->input("iface_subnet_{$n}"),
                        'ip_address' => $ipVal,
                        'member_id'  => $memberId,
                        'dhcp'       => 0,
                        'gateway'    => 0,
                        'service'    => 0,
                    ]);
                    $this->syncIp6Add($iface->id, $ipVal);
                }
            }
        });

        // Approve connection request if device was created from one
        if ($crId = (int) $request->input('connection_request_id')) {
            ConnectionRequest::where('id', $crId)
                ->where('state', ConnectionRequest::STATE_UNDECIDED)
                ->update([
                    'device_id'       => $deviceId,
                    'state'           => ConnectionRequest::STATE_APPROVED,
                    'decided_user_id' => auth()->id(),
                    'decided_at'      => now(),
                ]);
        }

        session()->flash('success', 'Zařízení bylo úspěšně přidáno.');
        return redirect()->route('devices.show', $deviceId);

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

        $preselectedUserId = $request->query('user_id') ? (int) $request->query('user_id') : null;
        $deviceTypes = EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)->orderBy('id')->pluck('value', 'id');
        $users = User::orderBy('surname')->orderBy('name')->get();

        return view('devices.create', [
            'deviceTypes'       => $deviceTypes,
            'users'             => $users,
            'preselectedUserId' => $preselectedUserId,
        ]);
    }

    public function store(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $data = $request->validate([
            'user_id'         => 'required|integer|exists:users,id',
            'name'            => 'required|string|max:255',
            'type'            => 'required|integer|exists:enum_types,id',
            'trade_name'      => 'nullable|string|max:50',
            'operating_system'=> 'nullable|integer',
            'PPPoE_logging_in'=> 'nullable|integer',
            'login'           => 'nullable|string|max:30',
            'password'        => 'nullable|string|max:30',
            'price'           => 'nullable|numeric|min:0',
            'payment_rate'    => 'nullable|numeric|min:0',
            'buy_date'        => 'nullable|date',
            'comment'         => 'nullable|string|max:254',
        ]);

        $device = Device::create($data);

        session()->flash('success', 'Zařízení bylo úspěšně přidáno.');
        return redirect()->route('devices.show', $device->id);
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $device = Device::find($id);
        if (!$device) {
            abort(404);
        }

        $deviceTypes = EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)->orderBy('id')->pluck('value', 'id');
        $users = User::orderBy('surname')->orderBy('name')->get();

        return view('devices.edit', [
            'device'             => $device,
            'deviceTypes'        => $deviceTypes,
            'users'              => $users,
            'canEditLogin'       => $this->can('view_all', 'login'),
            'canEditPassword'    => $this->can('view_all', 'password'),
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
            'user_id'         => 'required|integer|exists:users,id',
            'name'            => 'required|string|max:255',
            'type'            => 'required|integer|exists:enum_types,id',
            'trade_name'      => 'nullable|string|max:50',
            'operating_system'=> 'nullable|integer',
            'PPPoE_logging_in'=> 'nullable|integer',
            'price'           => 'nullable|numeric|min:0',
            'payment_rate'    => 'nullable|numeric|min:0',
            'buy_date'        => 'nullable|date',
            'comment'         => 'nullable|string|max:254',
        ];

        if ($this->can('view_all', 'login')) {
            $rules['login'] = 'nullable|string|max:30';
        }

        if ($this->can('view_all', 'password')) {
            $rules['password'] = 'nullable|string|max:30';
        }

        $data = $request->validate($rules);
        $device->update($data);

        session()->flash('success', 'Zařízení bylo úspěšně upraveno.');
        return redirect()->route('devices.show', $id);
    }

    public function destroy(int $id)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $device = Device::findOrFail($id);
        $userId = $device->user_id;

        DB::transaction(function () use ($device) {
            foreach ($device->ifaces as $iface) {
                $iface->ip6Addresses()->delete();
                $iface->ipAddresses()->delete();
            }
            $device->ifaces()->delete();
            $device->delete();
        });

        return redirect()->route('devices.by_user', $userId)
            ->with('success', 'Zařízení bylo smazáno.');
    }
}
