<?php

namespace App\Http\Controllers;

use App\Models\ConnectionRequest;
use App\Models\DeviceTemplate;
use App\Models\EmailQueue;
use App\Models\EnumType;
use App\Models\Member;
use App\Models\Setting;
use App\Models\Subnet;
use App\Services\SnmpMacDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    private function ipInSubnet(string $ip, string $network, string $netmask): bool
    {
        return (ip2long($ip) & ip2long($netmask)) === (ip2long($network) & ip2long($netmask));
    }

    /** Find subnet_id for an IP that is free and has no pending request */
    private function getSubnetForConnectionRequest(string $ip): ?int
    {
        $row = DB::selectOne("
            SELECT s.subnet_id FROM (
                SELECT s.id AS subnet_id
                FROM subnets s
                WHERE inet_aton(s.netmask) & inet_aton(?) = inet_aton(s.network_address)
            ) s
            LEFT JOIN ip_addresses ip ON ip.subnet_id = s.subnet_id
                AND inet_aton(ip.ip_address) = inet_aton(?)
            WHERE ? NOT IN (
                SELECT cr.ip_address FROM connection_requests cr WHERE cr.state = ?
            )
            GROUP BY s.subnet_id
            HAVING COUNT(ip.id) = 0
        ", [$ip, $ip, $ip, ConnectionRequest::STATE_UNDECIDED]);

        return $row?->subnet_id;
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
            'cr'      => $cr,
            'canEdit' => $this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_KEY)
                         && $cr->state === ConnectionRequest::STATE_UNDECIDED,
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function create(Request $request, int $subnetId, string $ipAddress = '')
    {
        $canNewAll = $this->aclCheck('new_all', self::ACL_SECTION, self::ACL_KEY);
        $canNewOwn = $this->aclCheck('new_own', self::ACL_SECTION, self::ACL_KEY);
        abort_unless($canNewAll || $canNewOwn, 403);
        $this->checkEnabled();

        $subnet = Subnet::findOrFail($subnetId);

        // Validate IP is in subnet range
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)
            || !$this->ipInSubnet($ipAddress, $subnet->network_address, $subnet->netmask)) {
            abort(400, 'IP adresa není v rozsahu subnetu.');
        }

        // Check IP still available
        if ($this->getSubnetForConnectionRequest($ipAddress) === null) {
            return redirect()->back()->with('warning', 'IP adresa již není volná nebo má čekající žádost.');
        }

        $members        = Member::orderBy('name')->pluck('name', 'id');
        $deviceTypes    = $this->deviceTypes();
        $templates      = DeviceTemplate::orderBy('name')->get(['id', 'name', 'enum_type_id']);
        $defaultType    = (int) Setting::get('connection_request_device_default_type', 0);
        $canEditDevices = $canNewAll && $this->aclCheck('new_all', 'Devices_Controller', 'devices');
        $authMemberId   = auth()->user()?->member_id;

        // Auto-detect MAC via SNMP on subnet gateway
        $detectedMac = app(SnmpMacDetector::class)->detectForSubnet($subnetId, $ipAddress);

        return view('connection_requests.create', compact(
            'subnet', 'ipAddress', 'members', 'deviceTypes', 'templates',
            'defaultType', 'canEditDevices', 'authMemberId', 'detectedMac'
        ));
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function store(Request $request)
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

        if (!$this->ipInSubnet($data['ip_address'], $subnet->network_address, $subnet->netmask)) {
            return back()->withErrors(['ip_address' => 'IP adresa není v rozsahu subnetu.'])->withInput();
        }

        // Check no undecided request for same IP
        if (ConnectionRequest::where('ip_address', $data['ip_address'])
                ->where('state', ConnectionRequest::STATE_UNDECIDED)->exists()) {
            return back()->withErrors(['ip_address' => 'Pro tuto IP adresu již existuje čekající žádost.'])->withInput();
        }

        $memberId = $canEditDevices ? (int) $data['member_id'] : auth()->user()?->member_id;

        $cr = ConnectionRequest::create([
            'member_id'          => $memberId,
            'added_user_id'      => auth()->id(),
            'decided_user_id'    => null,
            'state'              => ConnectionRequest::STATE_UNDECIDED,
            'created_at'         => now(),
            'decided_at'         => null,
            'ip_address'         => $data['ip_address'],
            'subnet_id'          => $data['subnet_id'],
            'mac_address'        => strtoupper($data['mac_address']),
            'device_id'          => null,
            'device_type_id'     => $data['device_type_id'],
            'device_template_id' => $data['device_template_id'],
            'comment'            => $data['comment'] ?? null,
            'comments_thread_id' => null,
        ]);

        // Notify admin email
        $notifyEmail = Setting::get('connection_request_notify_email');
        if ($notifyEmail) {
            $member = Member::find($memberId);
            EmailQueue::create([
                'from'    => Setting::get('email_default_email', 'freenetis@localhost'),
                'to'      => $notifyEmail,
                'subject' => 'Nová žádost o připojení — ' . $data['ip_address'],
                'body'    => 'Byla podána nová žádost o připojení.'
                    . "\n\nČlen: " . ($member?->name ?? "#{$memberId}")
                    . "\nIP adresa: " . $data['ip_address']
                    . "\nMAC adresa: " . strtoupper($data['mac_address'])
                    . "\n\nDetail: " . route('connection_requests.show', $cr->id),
                'state'   => EmailQueue::STATE_NEW,
            ]);
        }

        // Message 12 — notify applicant
        $memberName = Member::find($memberId)?->name ?? '';
        $this->sendMessageToMember(12, $memberId, [
            'member_name' => $memberName,
            'comment'     => $data['comment'] ?? '',
        ]);

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

    public function reject(Request $request, int $id)
    {
        abort_unless($this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_KEY), 403);
        $this->checkEnabled();

        $cr = ConnectionRequest::with('member')->findOrFail($id);

        if ($cr->state !== ConnectionRequest::STATE_UNDECIDED) {
            abort(400, 'Žádost již byla rozhodnuta.');
        }

        $cr->update([
            'state'           => ConnectionRequest::STATE_REJECTED,
            'decided_user_id' => auth()->id(),
            'decided_at'      => now(),
        ]);

        // Message 14 — notify applicant of rejection
        $this->sendMessageToMember(14, $cr->member_id, [
            'member_name' => $cr->member?->name ?? '',
            'comment'     => $cr->comment ?? '',
        ]);

        return redirect()->route('connection_requests.show', $id)
            ->with('success', 'Žádost byla zamítnuta.');
    }
}
