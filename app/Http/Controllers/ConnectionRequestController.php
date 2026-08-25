<?php

namespace App\Http\Controllers;

use App\Models\ConnectionRequest;
use App\Models\DeviceTemplate;
use App\Models\EmailQueue;
use App\Models\EnumType;
use App\Models\Member;
use App\Models\Setting;
use App\Models\Subnet;
use App\Models\User;
use App\Services\PppoeSecretService;
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
            'cr'           => $cr,
            'canEdit'      => $this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_KEY)
                         && $cr->state === ConnectionRequest::STATE_UNDECIDED,
            'pppoeEnabled' => (bool) Setting::get('pppoe_enabled', 0),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    /**
     * Seznam členů pro výběr vlastníka připojení.
     * Stejná logika jako picker v Devices: fyzické osoby zobrazí jako
     * „Příjmení Jméno" z hlavního uživatele (type = MAIN_USER), seřazené podle
     * příjmení; pokud surname/jméno chybí (organizace, legacy), fallback na
     * members.name.
     *
     * @return \Illuminate\Support\Collection<int, string>  id => popisek
     */
    private function membersForSelect(): \Illuminate\Support\Collection
    {
        return DB::table('members as m')
            ->join('users as u', function ($j) {
                $j->on('u.member_id', '=', 'm.id')
                  ->where('u.type', '=', User::MAIN_USER);
            })
            ->select('m.id', 'm.name', 'u.surname', 'u.name as user_name')
            ->orderBy('u.surname')
            ->orderBy('u.name')
            ->orderBy('u.id')
            ->get()
            ->unique('id')
            ->mapWithKeys(function ($row) {
                $surname = trim((string) $row->surname);
                $name    = trim((string) $row->user_name);
                $label   = ($surname !== '' && $name !== '')
                    ? $surname . ' ' . $name
                    : $row->name;
                return [$row->id => $label];
            });
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
    public function storeRequest(Request $request)
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
        if ($detected && ConnectionRequest::where('ip_address', $detected['ip'])
                ->where('state', ConnectionRequest::STATE_UNDECIDED)->exists()) {
            $detected = null;
        }

        // MAC je povinný — bez něj technik zařízení v DHCP nedohledá. Když se
        // podařilo detekovat přes SNMP, použijeme ho; jinak ho zadá zákazník.
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

        $mac = $hasDetectedMac
            ? $detected['mac']
            : strtoupper(str_replace('-', ':', $data['mac_address']));

        $cr = ConnectionRequest::create([
            'member_id'          => $memberId,
            'added_user_id'      => auth()->id(),
            'decided_user_id'    => null,
            'state'              => ConnectionRequest::STATE_UNDECIDED,
            'created_at'         => now(),
            'decided_at'         => null,
            'ip_address'         => $detected['ip'] ?? null,
            'subnet_id'          => $detected['subnet_id'] ?? null,
            'mac_address'        => $mac,
            'device_id'          => null,
            'device_type_id'     => $data['device_type_id'] ?? null,
            'device_template_id' => $data['device_template_id'] ?? null,
            'comment'            => $data['comment'] ?? null,
            'comments_thread_id' => null,
            ...$this->pppoeCredentialFor($memberId),
        ]);

        // Notifikace administrátora
        $notifyEmail = Setting::get('connection_request_notify_email');
        if ($notifyEmail) {
            $member = Member::find($memberId);
            EmailQueue::create([
                'from'    => Setting::get('email_default_email', 'freenetis@localhost'),
                'to'      => $notifyEmail,
                'subject' => 'Nová žádost o připojení — ' . ($member?->name ?? "#{$memberId}"),
                'body'    => 'Byla podána nová žádost o připojení (self-service).'
                    . "\n\nČlen: " . ($member?->name ?? "#{$memberId}")
                    . "\nIP adresa: " . ($detected['ip'] ?? '— doplní technik')
                    . "\nMAC adresa: " . $mac
                    . "\nPoznámka: " . ($data['comment'] ?? '—')
                    . "\n\nDetail: " . route('connection_requests.show', $cr->id),
                'state'   => EmailQueue::STATE_NEW,
            ]);
        }

        // Message 12 — potvrzení žadateli
        $this->sendMessageToMember(12, $memberId, [
            'member_name' => Member::find($memberId)?->name ?? '',
            'comment'     => $data['comment'] ?? '',
        ]);

        return redirect()->route('connection_requests.by_member', $memberId)
            ->with('success', 'Žádost o připojení byla odeslána. Ozveme se vám.');
    }

    /**
     * Zjistí, zda aktuální IP klienta je volná neregistrovaná adresa v nějakém
     * subnetu (situace „neznámé zařízení") — pak vrátí subnet + MAC (SNMP).
     *
     * @return array{subnet_id:int, ip:string, mac:?string}|null
     */
    private function detectCurrentConnection(Request $request): ?array
    {
        $ip = (string) $request->ip();
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $subnetId = $this->getSubnetForConnectionRequest($ip);
        if ($subnetId === null) {
            return null;
        }

        return [
            'subnet_id' => $subnetId,
            'ip'        => $ip,
            'mac'       => $this->detectMac($subnetId, $ip),
        ];
    }

    /**
     * MAC pro (subnet, ip). Se zapnutým PPPoE modulem nejdřív zkusí RADIUS
     * accounting (radacct — aktivní PPPoE session podle přidělené pool IP →
     * Calling-Station-Id), pak fallback na SNMP (starý MAC/IP režim). Během
     * přechodu běží oba: PPPoE klient přes radacct, DHCP klient přes SNMP.
     */
    private function detectMac(int $subnetId, string $ip): ?string
    {
        if (Setting::get('pppoe_enabled', 0)) {
            try {
                $raw = DB::table('radacct')
                    ->where('framedipaddress', $ip)
                    ->whereNull('acctstoptime')
                    ->orderByDesc('acctstarttime')
                    ->value('callingstationid');
                $mac = $raw ? strtoupper(str_replace('-', ':', trim($raw))) : null;
                if ($mac && preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
                    return $mac;
                }
            } catch (\Throwable $e) {
                \Log::warning('radacct MAC lookup selhal', ['ip' => $ip, 'error' => $e->getMessage()]);
            }
        }
        return app(SnmpMacDetector::class)->detectForSubnet($subnetId, $ip);
    }

    /**
     * PPPoE credential pro novou žádost — vygeneruje se jen se zapnutým modulem.
     * Uloží se na žádost, instalátor ho opíše do CPE; při schválení se překlopí
     * do pppoe_secrets. Vrací pole klíčů rovnou pro ConnectionRequest::create.
     *
     * @return array{pppoe_username: ?string, pppoe_secret: ?string}
     */
    private function pppoeCredentialFor(int $memberId): array
    {
        if (!Setting::get('pppoe_enabled', 0)) {
            return ['pppoe_username' => null, 'pppoe_secret' => null];
        }
        $c = app(PppoeSecretService::class)->buildCredential($memberId);
        return ['pppoe_username' => $c['username'], 'pppoe_secret' => $c['secret']];
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
            || !$this->ipInSubnet($ipAddress, $subnet->network_address, $subnet->netmask)) {
            abort(400, 'IP adresa není v rozsahu subnetu.');
        }

        // Check IP still available
        if ($this->getSubnetForConnectionRequest($ipAddress) === null) {
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
            ...$this->pppoeCredentialFor($memberId),
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
