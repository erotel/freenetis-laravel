<?php

namespace App\Http\Controllers;

use App\Domains\Access\Actions\RejectConnectionRequestAction;
use App\Domains\Access\Actions\SubmitConnectionRequestAction;
use App\Domains\Access\Onboarding\ConnectionDetector;
use App\Domains\Access\Repositories\ConnectionRequestRepository;
use App\Domains\Network\Queries\DeviceFormQuery;
use App\Models\ConnectionRequest;
use App\Models\DeviceTemplate;
use App\Models\EnumType;
use App\Models\Member;
use App\Models\Setting;
use App\Models\Subnet;
use Illuminate\Http\Request;

/** Žádosti o připojení (onboarding). Logika v app/Domains/Access. */
class ConnectionRequestController extends Controller
{
    const ACL_SECTION = 'Connection_Requests_Controller';
    const ACL_KEY     = 'request';

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function checkEnabled(): void
    {
        if (!Setting::get('connection_request_enable')) {
            abort(403, 'Connection requests are not enabled.');
        }
    }

    private function deviceTypes(): array
    {
        $types = EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)
            ->orderBy('value')
            ->pluck('value', 'id')
            ->toArray();

        $allowed = Setting::get('connection_request_device_types');
        if ($allowed) {
            $ids = explode(':', $allowed);
            $types = array_filter($types, fn($id) => in_array($id, $ids), ARRAY_FILTER_USE_KEY);
        }
        return $types;
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $canViewAll = $this->aclCheck('view_all', self::ACL_SECTION, self::ACL_KEY);
        $canViewOwn = $this->aclCheck('view_own', self::ACL_SECTION, self::ACL_KEY);
        abort_unless($canViewAll || $canViewOwn, 403);
        $this->checkEnabled();

        $query = ConnectionRequest::with(['member', 'subnet', 'addedUser'])
            ->orderByDesc('id');

        // view_own without view_all → restrict to own member's requests
        if (!$canViewAll && $canViewOwn) {
            $query->where('member_id', auth()->user()?->member_id);
        }

        if ($request->filled('state') && $request->state !== '') {
            $query->where('state', (int) $request->state);
        }
        if ($request->filled('ip_address')) {
            $query->where('ip_address', 'like', '%' . $request->ip_address . '%');
        }

        $items = $query->paginate(50)->withQueryString();

        return view('connection_requests.index', [
            'items'           => $items,
            'filterState'     => $request->input('state', ''),
            'filterIp'        => $request->input('ip_address', ''),
            'canEdit'         => $this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_KEY),
            'stateLabels'     => ConnectionRequest::STATE_LABELS,
        ]);
    }

    // ── Show by member ────────────────────────────────────────────────────────

    public function showByMember(int $memberId)
    {
        $canViewAll = $this->aclCheck('view_all', self::ACL_SECTION, self::ACL_KEY);
        $isOwnMember = ($memberId == auth()->user()?->member_id);
        $canViewOwn = $isOwnMember && $this->aclCheck('view_own', self::ACL_SECTION, self::ACL_KEY);
        abort_unless($canViewAll || $canViewOwn, 403);
        $this->checkEnabled();

        $member = Member::findOrFail($memberId);
        $items  = ConnectionRequest::with(['subnet', 'addedUser'])
            ->where('member_id', $memberId)
            ->orderByDesc('id')
            ->get();

        return view('connection_requests.by_member', [
            'member'      => $member,
            'items'       => $items,
            'canAdd'      => $this->aclCheck('new_all', self::ACL_SECTION, self::ACL_KEY)
                             || ($isOwnMember && $this->aclCheck('new_own', self::ACL_SECTION, self::ACL_KEY)),
            'stateLabels' => ConnectionRequest::STATE_LABELS,
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(int $id)
    {
        $canViewAll = $this->aclCheck('view_all', self::ACL_SECTION, self::ACL_KEY);
        $canViewOwn = $this->aclCheck('view_own', self::ACL_SECTION, self::ACL_KEY);
        abort_unless($canViewAll || $canViewOwn, 403);
        $this->checkEnabled();

        $cr = ConnectionRequest::with([
            'member', 'subnet', 'addedUser', 'decidedUser', 'deviceTemplate', 'deviceType',
        ])->findOrFail($id);

        // view_own: can only view own requests
        if (!$canViewAll && $canViewOwn) {
            abort_unless($cr->member_id == auth()->user()?->member_id, 403);
        }

        return view('connection_requests.show', [
            'cr'           => $cr,
            'canEdit'      => $this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_KEY)
                         && $cr->state === ConnectionRequest::STATE_UNDECIDED,
            'pppoeEnabled' => (bool) Setting::get('pppoe_enabled', 0),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    /**
     * Výběr vlastníka připojení: id => „Příjmení Jméno" hlavního uživatele
     * (fallback members.name) — stejný výběr jako u zařízení.
     */
    private function membersForSelect(): \Illuminate\Support\Collection
    {
        return app(DeviceFormQuery::class)->membersForPicker()->mapWithKeys(fn ($row) => [$row->id => $row->display_name]);
    }

    /**
     * Self-service formulář pro zákazníka („Požádat o nové připojení").
     *
     * Proaktivní žádost — funguje odkudkoli, IP/MAC/subnet nejsou povinné.
     * Pokud zákazník portál náhodou otevřel z nově připojeného zařízení, jehož
     * IP je zatím neregistrovaná (stejná situace jako neznámé zařízení přes SNMP),
     * předvyplníme IP a MAC (detekováno přes SNMP) — technik pak nemusí nic dohledávat.
     * Jinak IP/MAC doplní technik až při schválení.
     */
    public function requestNew(Request $request)
    {
        $canNewAll = $this->aclCheck('new_all', self::ACL_SECTION, self::ACL_KEY);
        $canNewOwn = $this->aclCheck('new_own', self::ACL_SECTION, self::ACL_KEY);
        abort_unless($canNewAll || $canNewOwn, 403);
        $this->checkEnabled();

        $detected = $this->detectCurrentConnection($request);

        return view('connection_requests.request', [
            'deviceTypes' => $this->deviceTypes(),
            'templates'   => DeviceTemplate::orderBy('name')->get(['id', 'name', 'enum_type_id']),
            'defaultType' => (int) Setting::get('connection_request_device_default_type', 0),
            'detected'    => $detected, // ['subnet_id','ip','mac'] nebo null
        ]);
    }

    /**
     * Uloží proaktivní self-service žádost (member_id = přihlášený člen).
     */
    public function storeRequest(Request $request, ConnectionRequestRepository $requests, SubmitConnectionRequestAction $submit)
    {
        $canNewAll = $this->aclCheck('new_all', self::ACL_SECTION, self::ACL_KEY);
        $canNewOwn = $this->aclCheck('new_own', self::ACL_SECTION, self::ACL_KEY);
        abort_unless($canNewAll || $canNewOwn, 403);
        $this->checkEnabled();

        $memberId = auth()->user()?->member_id;
        if (!$memberId) {
            return redirect()->back()->with('error', 'Váš účet nemá přiřazeného člena, žádost nelze podat.');
        }

        // IP/MAC z hidden polí NEDŮVĚŘUJEME — znovu detekujeme z aktuální IP na serveru.
        $detected = $this->detectCurrentConnection($request);

        // Brání duplicitní čekající žádosti pro stejnou (detekovanou) IP.
        if ($detected && $requests->hasPendingForIp($detected['ip'])) {
            $detected = null;
        }

        // MAC je povinný — bez něj technik zařízení v DHCP nedohledá. Detekovaný
        // (radacct/SNMP) má přednost, jinak ho zadá zákazník.
        $hasDetectedMac = $detected && !empty($detected['mac']);

        $rules = [
            'device_type_id'     => 'nullable|integer|exists:enum_types,id',
            'device_template_id' => 'nullable|integer|exists:device_templates,id',
            'comment'            => 'nullable|string|max:1000',
        ];
        if (!$hasDetectedMac) {
            $rules['mac_address'] = ['required', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/'];
        }
        $data = $request->validate($rules, [], ['mac_address' => 'MAC adresa']);

        $submit->handle($memberId, auth()->id(), [
            'ip_address'         => $detected['ip'] ?? null,
            'subnet_id'          => $detected['subnet_id'] ?? null,
            'mac_address'        => $hasDetectedMac ? $detected['mac'] : strtoupper(str_replace('-', ':', $data['mac_address'])),
            'device_type_id'     => $data['device_type_id'] ?? null,
            'device_template_id' => $data['device_template_id'] ?? null,
            'comment'            => $data['comment'] ?? null,
        ], selfService: true);

        return redirect()->route('connection_requests.by_member', $memberId)
            ->with('success', 'Žádost o připojení byla odeslána. Ozveme se vám.');
    }

    /** Aktuální připojení klienta = volná neregistrovaná IP? @return ?array{subnet_id:int, ip:string, mac:?string} */
    private function detectCurrentConnection(Request $request): ?array
    {
        return app(ConnectionDetector::class)->detect((string) $request->ip());
    }

    /** MAC pro (subnet, IP) — radacct (PPPoE) primárně, jinak SNMP; viz ConnectionDetector. */
    private function detectMac(int $subnetId, string $ip): ?string
    {
        return app(ConnectionDetector::class)->mac($subnetId, $ip);
    }

    public function create(Request $request, int $subnetId, string $ipAddress = '')
    {
        $canNewAll = $this->aclCheck('new_all', self::ACL_SECTION, self::ACL_KEY);
        $canNewOwn = $this->aclCheck('new_own', self::ACL_SECTION, self::ACL_KEY);
        abort_unless($canNewAll || $canNewOwn, 403);
        $this->checkEnabled();

        $subnet = Subnet::findOrFail($subnetId);

        // Validate IP is in subnet range
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)
            || !ConnectionDetector::ipInSubnet($ipAddress, $subnet->network_address, $subnet->netmask)) {
            abort(400, 'IP adresa není v rozsahu subnetu.');
        }

        // Check IP still available
        if (app(ConnectionRequestRepository::class)->freeSubnetFor($ipAddress) === null) {
            return redirect()->back()->with('warning', 'IP adresa již není volná nebo má čekající žádost.');
        }

        $members        = $this->membersForSelect();
        $deviceTypes    = $this->deviceTypes();
        $templates      = DeviceTemplate::orderBy('name')->get(['id', 'name', 'enum_type_id']);
        $defaultType    = (int) Setting::get('connection_request_device_default_type', 0);
        $canEditDevices = $canNewAll && $this->aclCheck('new_all', 'Devices_Controller', 'devices');
        $authMemberId   = auth()->user()?->member_id;

        // Auto-detect MAC — radacct (PPPoE) primárně, jinak SNMP (viz detectMac).
        $detectedMac = $this->detectMac($subnetId, $ipAddress);

        return view('connection_requests.create', compact(
            'subnet', 'ipAddress', 'members', 'deviceTypes', 'templates',
            'defaultType', 'canEditDevices', 'authMemberId', 'detectedMac'
        ));
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function store(Request $request, ConnectionRequestRepository $requests, SubmitConnectionRequestAction $submit)
    {
        $canNewAll = $this->aclCheck('new_all', self::ACL_SECTION, self::ACL_KEY);
        $canNewOwn = $this->aclCheck('new_own', self::ACL_SECTION, self::ACL_KEY);
        abort_unless($canNewAll || $canNewOwn, 403);
        $this->checkEnabled();

        $canEditDevices = $canNewAll && $this->aclCheck('new_all', 'Devices_Controller', 'devices');

        $rules = [
            'subnet_id'          => 'required|integer|exists:subnets,id',
            'ip_address'         => 'required|ip',
            'mac_address'        => ['required', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/'],
            'device_type_id'     => 'required|integer|exists:enum_types,id',
            'device_template_id' => 'required|integer|exists:device_templates,id',
            'comment'            => 'nullable|string|max:1000',
        ];
        if ($canEditDevices) {
            $rules['member_id'] = 'required|integer|exists:members,id';
        }

        $data = $request->validate($rules);

        $subnet = Subnet::findOrFail($data['subnet_id']);

        if (!ConnectionDetector::ipInSubnet($data['ip_address'], $subnet->network_address, $subnet->netmask)) {
            return back()->withErrors(['ip_address' => 'IP adresa není v rozsahu subnetu.'])->withInput();
        }
        if ($requests->hasPendingForIp($data['ip_address'])) {
            return back()->withErrors(['ip_address' => 'Pro tuto IP adresu již existuje čekající žádost.'])->withInput();
        }

        $memberId = $canEditDevices ? (int) $data['member_id'] : auth()->user()?->member_id;

        $submit->handle($memberId, auth()->id(), [
            'ip_address'         => $data['ip_address'],
            'subnet_id'          => $data['subnet_id'],
            'mac_address'        => strtoupper($data['mac_address']),
            'device_type_id'     => $data['device_type_id'],
            'device_template_id' => $data['device_template_id'],
            'comment'            => $data['comment'] ?? null,
        ], selfService: false);

        return redirect()->route('connection_requests.by_member', $memberId)
            ->with('success', 'Žádost o připojení byla odeslána.');
    }

    // ── Approve ───────────────────────────────────────────────────────────────

    public function approve(Request $request, int $id)
    {
        abort_unless($this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_KEY), 403);
        $this->checkEnabled();

        $cr = ConnectionRequest::findOrFail($id);

        if ($cr->state !== ConnectionRequest::STATE_UNDECIDED) {
            abort(400, 'Žádost již byla rozhodnuta.');
        }

        return redirect()->route('devices.create_from_cr', $cr->id)
            ->with('info', 'Vytvořte zařízení pro žadatele — po uložení bude žádost automaticky schválena.');
    }

    // ── Reject ────────────────────────────────────────────────────────────────

    public function reject(Request $request, int $id, RejectConnectionRequestAction $reject)
    {
        abort_unless($this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_KEY), 403);
        $this->checkEnabled();

        $cr = ConnectionRequest::with('member')->findOrFail($id);

        if ($cr->state !== ConnectionRequest::STATE_UNDECIDED) {
            abort(400, 'Žádost již byla rozhodnuta.');
        }

        $reject->handle($cr, auth()->id());

        return redirect()->route('connection_requests.show', $id)
            ->with('success', 'Žádost byla zamítnuta.');
    }
}
