<?php

namespace App\Http\Controllers;

use App\Domains\Network\Actions\RemoveIfaceAction;
use App\Domains\Network\Actions\UpdateIfaceAction;
use App\Domains\Network\Exceptions\IfaceIpRejected;
use App\Domains\Network\Repositories\IfaceRepository;
use App\Models\Iface;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Síťová rozhraní zařízení (IP, odvozená IPv6, DHCP, přípojná místa); logika v doméně Network. */
class IfaceController extends Controller
{
    private const MAC_REGEX = 'regex:/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/';
    private const MAC_MESSAGES = [
        'mac.regex'  => 'MAC adresa musí být ve formátu aa:bb:cc:dd:ee:ff.',
        'mac.unique' => 'Tato MAC adresa je již použita.',
    ];

    public function __construct(private IfaceRepository $ifaces) {}

    private function can(string $action = 'view_all'): bool
    {
        return $this->aclCheck($action, 'Ifaces_Controller', 'iface');
    }

    public function index(Request $request)
    {
        abort_unless($this->can(), 403);

        $sort    = in_array($request->sort, ['id', 'name', 'type', 'device_id']) ? $request->sort : 'id';
        $dir     = $request->dir === 'desc' ? 'desc' : 'asc';
        $perPage = (int) ($request->record_per_page ?? 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300])) {
            $perPage = 50;
        }
        $search = trim((string) ($request->search ?? ''));
        $ifaces = $this->ifaces->paginate($sort, $dir, $search, $perPage);

        return view('ifaces.index', compact('ifaces', 'sort', 'dir', 'perPage', 'search'));
    }

    public function create(?int $deviceId = null)
    {
        abort_unless($this->can('new_all'), 403);
        return view('ifaces.create', ['device' => $deviceId ? $this->ifaces->deviceOrFail($deviceId) : null, 'types' => Iface::typeLabels()]);
    }

    public function store(Request $request)
    {
        abort_unless($this->can('new_all'), 403);

        $type = (int) $request->input('type');
        $validated = $request->validate([
            'device_id' => 'required|integer|exists:devices,id',
            'type'      => 'required|integer|in:1,2,3,4,5,6,7',
            'name'      => 'required|string|max:254',
            'mac'       => [($type === Iface::ETHERNET || $type === Iface::WIRELESS) ? 'required' : 'nullable', 'string', self::MAC_REGEX, 'unique:ifaces,mac'],
            'comment'   => 'nullable|string|max:254',
        ], self::MAC_MESSAGES);

        $iface = $this->ifaces->create($validated);
        session()->flash('success', 'Rozhraní bylo úspěšně přidáno.');
        return redirect()->route('devices.show', $iface->device_id);
    }

    public function show(int $id)
    {
        abort_unless($this->can(), 403);
        return view('ifaces.show', ['iface' => $this->ifaces->findForDetailOrFail($id)]);
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);
        return view('ifaces.edit', [
            'iface'      => $this->ifaces->findForEditOrFail($id),
            'subnets'    => $this->ifaces->subnets(),
            'subnetData' => $this->ifaces->subnetsWithFreeIps(),
        ]);
    }

    public function update(Request $request, int $id, UpdateIfaceAction $update)
    {
        abort_unless($this->can('edit_all'), 403);
        $iface = $this->ifaces->findWithIpsOrFail($id);

        // IP adresy se kontrolují všechny předem — nic se neuloží napůl.
        $rules = [
            'name'           => 'required|string|max:254',
            'mac'            => ['nullable', 'string', self::MAC_REGEX, Rule::unique('ifaces', 'mac')->ignore($id)],
            'comment'        => 'nullable|string|max:254',
            'new_ip_address' => ['nullable', 'ipv4', new \App\Rules\IpInSubnet($request->input('new_ip_subnet'))],
            'new_ip_subnet'  => 'nullable|integer|exists:subnets,id',
        ];
        $messages = self::MAC_MESSAGES + ['new_ip_address.ipv4' => 'Neplatný formát IP adresy.'];
        foreach ($iface->ipAddresses as $n => $ip) {
            $rules["ip_address_{$n}"] = ['nullable', 'ipv4', new \App\Rules\IpInSubnet($request->input("ip_subnet_{$n}"))];
            $rules["ip_subnet_{$n}"]  = 'nullable|integer|exists:subnets,id';
            $messages["ip_address_{$n}.ipv4"] = 'Neplatný formát IP adresy.';
        }
        $validated = collect($request->validate($rules, $messages))->only(['name', 'mac', 'comment'])->all();

        $ipChanges = [];
        foreach ($iface->ipAddresses as $n => $ip) {
            $ipChanges[$n] = [$request->input("ip_address_{$n}"), $request->input("ip_subnet_{$n}")];
        }

        try {
            $update->handle($iface, $validated, $ipChanges, [$request->input('new_ip_address'), $request->input('new_ip_subnet')]);
        } catch (IfaceIpRejected $e) {
            return back()->withInput()->withErrors([$e->field => $e->getMessage()]);
        }

        session()->flash('success', 'Rozhraní bylo úspěšně upraveno.');
        return redirect()->route('devices.show', $iface->device_id);
    }

    public function destroy(int $id, RemoveIfaceAction $remove)
    {
        abort_unless($this->can('delete_all'), 403);
        $iface = $this->ifaces->findForRemovalOrFail($id);
        $deviceId = $iface->device_id;

        $remove->handle($iface);

        session()->flash('success', 'Rozhraní bylo smazáno.');
        return redirect()->route('devices.show', $deviceId);
    }
}
