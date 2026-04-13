<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SyncsIp6Address;
use App\Models\Device;
use App\Models\Iface;
use App\Models\IpAddress;
use App\Models\Subnet;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IfaceController extends Controller
{
    use SyncsIp6Address;
    private function can(string $action = 'view_all'): bool
    {
        return $this->aclCheck($action, 'Ifaces_Controller', 'iface');
    }

    public function index(Request $request)
    {
        abort_unless($this->can(), 403);

        $sort = in_array($request->sort, ['id', 'name', 'type', 'device_id']) ? $request->sort : 'id';
        $dir  = $request->dir === 'desc' ? 'desc' : 'asc';
        $perPage = (int) ($request->record_per_page ?? 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300])) {
            $perPage = 50;
        }

        $search = trim((string) ($request->search ?? ''));

        $query = Iface::with('device.user.member')->orderBy($sort, $dir);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('mac', 'like', "%{$search}%");
            });
        }

        $ifaces = $query->paginate($perPage)->withQueryString();

        return view('ifaces.index', compact('ifaces', 'sort', 'dir', 'perPage', 'search'));
    }

    public function create(int $deviceId = null)
    {
        abort_unless($this->can('new_all'), 403);

        $device = $deviceId ? Device::findOrFail($deviceId) : null;
        $types  = Iface::typeLabels();

        return view('ifaces.create', compact('device', 'types'));
    }

    public function store(Request $request)
    {
        abort_unless($this->can('new_all'), 403);

        $type = (int) $request->input('type');

        $validated = $request->validate([
            'device_id' => 'required|integer|exists:devices,id',
            'type'      => 'required|integer|in:1,2,3,4,5,6,7',
            'name'      => 'required|string|max:254',
            'mac'       => [
                ($type === Iface::ETHERNET || $type === Iface::WIRELESS) ? 'required' : 'nullable',
                'string',
                'regex:/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/',
                'unique:ifaces,mac',
            ],
            'comment'   => 'nullable|string|max:254',
        ], [
            'mac.regex'  => 'MAC adresa musí být ve formátu aa:bb:cc:dd:ee:ff.',
            'mac.unique' => 'Tato MAC adresa je již použita.',
        ]);

        $iface = Iface::create($validated);

        session()->flash('success', 'Rozhraní bylo úspěšně přidáno.');

        return redirect()->route('devices.show', $iface->device_id);
    }

    public function show(int $id)
    {
        abort_unless($this->can(), 403);

        $iface = Iface::with([
            'device.user.member',
            'ipAddresses.subnet',
            'vlans',
        ])->findOrFail($id);

        return view('ifaces.show', compact('iface'));
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $iface   = Iface::with(['device', 'ipAddresses.subnet'])->findOrFail($id);
        $subnets = Subnet::orderBy('name')->get();

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

        return view('ifaces.edit', compact('iface', 'subnets', 'subnetData'));
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $iface = Iface::with('ipAddresses')->findOrFail($id);

        $validated = $request->validate([
            'name'    => 'required|string|max:254',
            'mac'     => [
                'nullable', 'string',
                'regex:/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/',
                Rule::unique('ifaces', 'mac')->ignore($id),
            ],
            'comment' => 'nullable|string|max:254',
        ], [
            'mac.regex'  => 'MAC adresa musí být ve formátu aa:bb:cc:dd:ee:ff.',
            'mac.unique' => 'Tato MAC adresa je již použita.',
        ]);

        $iface->update($validated);

        // Update existing IP addresses
        foreach ($iface->ipAddresses as $n => $existingIp) {
            $newIpVal = $request->input("ip_address_{$n}");
            $subnetId = $request->input("ip_subnet_{$n}");
            if ($newIpVal && $subnetId) {
                if ($newIpVal !== $existingIp->ip_address) {
                    if (IpAddress::where('ip_address', $newIpVal)->where('subnet_id', $subnetId)->where('id', '!=', $existingIp->id)->exists()) {
                        return back()->withInput()->withErrors(["ip_address_{$n}" => "IP adresa {$newIpVal} je již použita."]);
                    }
                }
                $existingIp->update([
                    'ip_address' => $newIpVal,
                    'subnet_id'  => (int) $subnetId,
                ]);
            }
        }

        // Add new IP if filled
        $newIp     = $request->input('new_ip_address');
        $newSubnet = $request->input('new_ip_subnet');
        if ($newIp && $newSubnet) {
            if (IpAddress::where('ip_address', $newIp)->where('subnet_id', $newSubnet)->exists()) {
                return back()->withInput()->withErrors(['new_ip_address' => "IP adresa {$newIp} je již použita."]);
            }
            $memberId = $iface->device?->user?->member_id;
            IpAddress::create([
                'iface_id'   => $iface->id,
                'subnet_id'  => (int) $newSubnet,
                'ip_address' => $newIp,
                'member_id'  => $memberId,
                'dhcp'       => 0,
                'gateway'    => 0,
                'service'    => 0,
            ]);
            $this->syncIp6Add($iface->id, $newIp);
        }

        session()->flash('success', 'Rozhraní bylo úspěšně upraveno.');
        return redirect()->route('devices.show', $iface->device_id);
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);

        $iface = Iface::findOrFail($id);
        $deviceId = $iface->device_id;

        $iface->ip6Addresses()->delete();
        $iface->ipAddresses()->delete();
        $iface->delete();

        session()->flash('success', 'Rozhraní bylo smazáno.');
        return redirect()->route('devices.show', $deviceId);
    }
}
