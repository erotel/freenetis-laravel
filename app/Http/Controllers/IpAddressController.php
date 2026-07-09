<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SyncsIp6Address;
use App\Models\Iface;
use App\Models\IpAddress;
use App\Models\Member;
use App\Models\Subnet;
use App\Services\AllowedSubnetSyncService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IpAddressController extends Controller
{
    use SyncsIp6Address;
    private const ACL_SECTION = 'Ip_addresses_Controller';
    private const ACL_VALUE   = 'ip_address';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index(Request $request)
    {
        $allowedSorts = ['id', 'ip_address', 'subnet_id', 'member_id'];
        $sort = in_array($request->query('sort'), $allowedSorts, true)
            ? $request->query('sort')
            : 'id';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->query('record_per_page', 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300, 350, 400, 450, 500], true)) {
            $perPage = 50;
        }

        $search = trim((string) $request->query('search', ''));

        $query = IpAddress::with(['subnet', 'member', 'iface.device']);

        if ($search !== '') {
            $query->where('ip_address', 'like', "%{$search}%");
        }

        $ipAddresses = $query->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();

        return view('ip_addresses.index', [
            'ipAddresses' => $ipAddresses,
            'sort'        => $sort,
            'dir'         => $dir,
            'perPage'     => $perPage,
            'search'      => $search,
            'canNew'      => $this->can('new_all'),
            'canEdit'     => $this->can('edit_all'),
            'canDelete'   => $this->can('delete_all'),
        ]);
    }

    public function show(int $id)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $ip = IpAddress::with(['subnet', 'member', 'iface.device'])->find($id);
        if (!$ip) {
            abort(404);
        }

        return view('ip_addresses.show', [
            'ip'        => $ip,
            'canEdit'   => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
        ]);
    }

    public function create(Request $request, ?int $deviceId = null)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        // Resolve device/iface from route param or query string
        $deviceId = $deviceId ?? ($request->query('device_id') ? (int) $request->query('device_id') : null);
        $ifaceId  = $request->query('iface_id') ? (int) $request->query('iface_id') : null;

        [$ifaces, $subnets, $members, $preselectedIfaceId, $preselectedMemberId] =
            $this->formData($deviceId, $ifaceId);

        return view('ip_addresses.create', compact(
            'ifaces', 'subnets', 'members', 'preselectedIfaceId', 'preselectedMemberId'
        ));
    }

    public function store(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $data = $this->validateIpForm($request);
        $data = $this->normalizeOwner($data);

        if ($request->boolean('gateway') && $this->gatewayExistsInSubnet((int) $data['subnet_id'])) {
            return back()->withInput()
                ->withErrors(['gateway' => 'V tomto subnetu již existuje gateway.']);
        }

        $ip = IpAddress::create($data);
        $this->syncIp6Add($ip->iface_id, $ip->ip_address);
        $ip->subnet?->setExpired();
        $this->syncAllowedSubnetOnAdd($ip);

        // Pokud IP patří čekajícímu zákazníkovi (type=18), okamžitě aktivovat
        // přesměrování — admin nemusí čekat na hodinový cron.
        $memberId = $ip->member_id
            ?? Iface::find($ip->iface_id)?->device?->user?->member_id;
        if ($memberId) {
            app(\App\Services\PendingCustomerRedirectService::class)->refreshForMember((int) $memberId);
        }

        session()->flash('success', 'IP adresa byla úspěšně přidána.');
        return redirect()->route('ip_addresses.show', $ip->id);
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $ip = IpAddress::find($id);
        if (!$ip) {
            abort(404);
        }

        [$ifaces, $subnets, $members] = $this->formData();

        return view('ip_addresses.edit', compact('ip', 'ifaces', 'subnets', 'members'));
    }

    public function update(Request $request, int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $ip = IpAddress::find($id);
        if (!$ip) {
            abort(404);
        }

        $data = $this->validateIpForm($request, $id);
        $data = $this->normalizeOwner($data);

        if ($request->boolean('gateway') && !$ip->gateway &&
            $this->gatewayExistsInSubnet((int) $data['subnet_id'], $id)) {
            return back()->withInput()
                ->withErrors(['gateway' => 'V tomto subnetu již existuje gateway.']);
        }

        $oldSubnetId = $ip->subnet_id;
        $this->syncIp6Delete($ip->ip_address);
        $ip->update($data);
        $this->syncIp6Add($ip->iface_id, $ip->ip_address);

        $ip->subnet?->setExpired();
        if ($oldSubnetId !== $ip->subnet_id) {
            $oldSubnet = Subnet::find($oldSubnetId);
            $oldSubnet?->setExpired();
        }
        $this->syncAllowedSubnetOnUpdate($ip, $oldSubnetId);

        session()->flash('success', 'IP adresa byla úspěšně upravena.');
        return redirect()->route('ip_addresses.show', $id);
    }

    public function destroy(int $id)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $ip = IpAddress::find($id);
        if (!$ip) {
            abort(404);
        }

        $subnet      = $ip->subnet;
        $subnetId    = $ip->subnet_id;
        $memberId    = $this->memberIdOfIp($ip);
        $this->syncIp6Delete($ip->ip_address);
        $ip->delete();
        $subnet?->setExpired();
        if ($memberId && $subnetId) {
            app(AllowedSubnetSyncService::class)->onIpRemoved($memberId, $subnetId);
        }

        session()->flash('success', 'IP adresa byla úspěšně smazána.');
        return redirect()->route('ip_addresses.index');
    }

    // -------------------------------------------------------------------------

    /**
     * IP navázaná na rozhraní (iface) odvozuje vlastníka ze zařízení
     * (device → user → member). Vlastní member_id na takovém řádku by vytvořil
     * dvojí vlastnictví: přežije i po změně vlastníka zařízení a zdvojuje IP
     * v qos_json (OR-join na member_id) i špatně směruje redirecty
     * (COALESCE(ip.member_id, u.member_id)). Proto ho u iface-vázaných IP nulujeme.
     */
    private function normalizeOwner(array $data): array
    {
        if (!empty($data['iface_id'])) {
            $data['member_id'] = null;
        }

        return $data;
    }

    private function memberIdOfIp(IpAddress $ip): ?int
    {
        // member_id na ip_addresses je nullable (gateway/service IP); pro
        // allowed_subnets potřebujeme membera vlastnícího device přes ifaces.
        $ip->loadMissing('iface.device.user');
        return (int) ($ip->iface?->device?->user?->member_id ?? 0) ?: null;
    }

    private function syncAllowedSubnetOnAdd(IpAddress $ip): void
    {
        $memberId = $this->memberIdOfIp($ip);
        if ($memberId && $ip->subnet_id) {
            app(AllowedSubnetSyncService::class)->onIpAdded($memberId, (int) $ip->subnet_id);
        }
    }

    private function syncAllowedSubnetOnUpdate(IpAddress $ip, ?int $oldSubnetId): void
    {
        $memberId = $this->memberIdOfIp($ip);
        if ($memberId && $ip->subnet_id) {
            app(AllowedSubnetSyncService::class)
                ->onIpMoved($memberId, $oldSubnetId, (int) $ip->subnet_id);
        }
    }

    private function formData(?int $deviceId = null, ?int $ifaceId = null): array
    {
        $ifaces  = Iface::with('device')->orderBy('name')->get();
        $subnets = Subnet::orderBy('network_address')->get();
        $members = Member::orderBy('name')->get();

        $preselectedIfaceId  = $ifaceId;
        $preselectedMemberId = null;

        if ($ifaceId) {
            $iface = $ifaces->firstWhere('id', $ifaceId);
            $preselectedMemberId = $iface?->device?->user?->member_id;
        } elseif ($deviceId) {
            $firstIface = $ifaces->firstWhere('device_id', $deviceId);
            if ($firstIface) {
                $preselectedIfaceId  = $firstIface->id;
                $preselectedMemberId = $firstIface->device?->user?->member_id;
            }
        }

        return [$ifaces, $subnets, $members, $preselectedIfaceId, $preselectedMemberId];
    }

    private function validateIpForm(Request $request, ?int $excludeId = null): array
    {
        $subnetId = (int) $request->input('subnet_id');

        return $request->validate([
            'iface_id'   => 'nullable|integer|exists:ifaces,id',
            'subnet_id'  => 'required|integer|exists:subnets,id',
            'member_id'  => 'nullable|integer|exists:members,id',
            'ip_address' => [
                'required',
                'string',
                'max:15',
                'regex:/^\d{1,3}(\.\d{1,3}){3}$/',
                Rule::unique('ip_addresses')->where('subnet_id', $subnetId)
                    ->ignore($excludeId),
            ],
            'dhcp'    => 'nullable|boolean',
            'gateway' => 'nullable|boolean',
            'service' => 'nullable|boolean',
        ], [
            'ip_address.regex'  => 'Neplatný formát IP adresy.',
            'ip_address.unique' => 'Tato IP adresa je již v daném subnetu použita.',
        ]);
    }

    private function gatewayExistsInSubnet(int $subnetId, ?int $excludeId = null): bool
    {
        $query = IpAddress::where('subnet_id', $subnetId)->where('gateway', 1);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

}
