<?php

namespace App\Http\Controllers;

use App\Domains\Network\Actions\AddIpAddressAction;
use App\Domains\Network\Actions\RemoveIpAddressAction;
use App\Domains\Network\Actions\UpdateIpAddressAction;
use App\Domains\Network\Repositories\IpAddressRepository;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** IPv4 adresy (rozhraní / přímo členovi); logika v doméně Network. */
class IpAddressController extends Controller
{
    public function __construct(private IpAddressRepository $ips) {}
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

        $ipAddresses = $this->ips->paginate($search, $sort, $dir, $perPage);

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

        $ip = $this->ips->findForDetail($id);
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

        // IPoE optika: když port zákazníka už drží IP z poolu (čerstvé zapojení),
        // navrhni tuhle IP jako fixní — po registraci mu tak zůstane a nezmění se.
        // Viz KeaControlClient::currentLeaseIp / [[project_lineid_tr101_gotcha]].
        $suggestedIp = $suggestedSubnetId = null;
        if ($preselectedIfaceId) {
            $mac = $ifaces->firstWhere('id', $preselectedIfaceId)?->mac;
            if ($mac && ($lease = app(\App\Domains\Access\Kea\KeaControlClient::class)->currentLeaseIp($mac))) {
                $sid = $this->subnetIdForIp($subnets, $lease);
                if ($sid !== null) { // jen IPoE subnet (dhcp+ipoe) → jinak nenavrhovat
                    $suggestedIp = $lease;
                    $suggestedSubnetId = $sid;
                }
            }
        }

        return view('ip_addresses.create', compact(
            'ifaces', 'subnets', 'members', 'preselectedIfaceId', 'preselectedMemberId',
            'suggestedIp', 'suggestedSubnetId'
        ));
    }

    public function store(Request $request, AddIpAddressAction $add)
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

        $ip = $add->handle($data);

        session()->flash('success', 'IP adresa byla úspěšně přidána.');
        return redirect()->route('ip_addresses.show', $ip->id);
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $ip = $this->ips->find($id);
        if (!$ip) {
            abort(404);
        }

        [$ifaces, $subnets, $members] = $this->formData();

        return view('ip_addresses.edit', compact('ip', 'ifaces', 'subnets', 'members'));
    }

    public function update(Request $request, int $id, UpdateIpAddressAction $update)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $ip = $this->ips->find($id);
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

        $update->handle($ip, $data);

        session()->flash('success', 'IP adresa byla úspěšně upravena.');
        return redirect()->route('ip_addresses.show', $id);
    }

    public function destroy(int $id, RemoveIpAddressAction $remove)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $ip = $this->ips->find($id);
        if (!$ip) {
            abort(404);
        }

        $remove->handle($ip);

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




    private function formData(?int $deviceId = null, ?int $ifaceId = null): array
    {
        $ifaces  = $this->ips->ifacesForSelect();
        $subnets = $this->ips->subnetsForSelect();
        $members = $this->ips->membersForSelect();

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
                'ipv4',
                new \App\Rules\IpInSubnet($request->input('subnet_id')),
                Rule::unique('ip_addresses')->where('subnet_id', $subnetId)
                    ->ignore($excludeId),
            ],
            'dhcp'    => 'nullable|boolean',
            'gateway' => 'nullable|boolean',
            'service' => 'nullable|boolean',
        ], [
            'ip_address.ipv4'   => 'Neplatný formát IP adresy.',
            'ip_address.unique' => 'Tato IP adresa je již v daném subnetu použita.',
        ]);
    }

    private function gatewayExistsInSubnet(int $subnetId, ?int $excludeId = null): bool
    {
        return $this->ips->gatewayExists($subnetId, $excludeId);
    }

    /**
     * ID IPoE subnetu (dhcp=1 AND ipoe=1), do kterého spadá daná IP — nebo null.
     * Jen IPoE subnety, aby se návrh „převzít lease IP" nenabízel mimo optiku.
     * @param \Illuminate\Support\Collection<int,Subnet> $subnets
     */
    private function subnetIdForIp($subnets, string $ip): ?int
    {
        $ipL = ip2long($ip);
        if ($ipL === false) {
            return null;
        }
        foreach ($subnets as $s) {
            if (!$s->dhcp || !$s->ipoe || !$s->network_address || !$s->netmask) {
                continue;
            }
            $net  = ip2long($s->network_address);
            $mask = ip2long($s->netmask);
            if ($net === false || $mask === false) {
                continue;
            }
            if (($ipL & $mask) === ($net & $mask)) {
                return (int) $s->id;
            }
        }
        return null;
    }

}
