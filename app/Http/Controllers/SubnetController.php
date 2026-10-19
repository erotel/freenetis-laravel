<?php

namespace App\Http\Controllers;

use App\Domains\Access\Repositories\LineIdRepository;
use App\Domains\Network\Actions\UpdateSubnetAction;
use App\Domains\Network\Repositories\SubnetRepository;
use App\Http\Filters\SubnetFilter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubnetController extends Controller
{
    private const ACL_SECTION = 'Subnets_Controller';
    private const ACL_VALUE   = 'subnet';

    public function __construct(private SubnetRepository $subnets)
    {
    }

    private function can(string $action, string $value = self::ACL_VALUE): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, $value);
    }

    public function index(Request $request)
    {
        $allowedSorts = ['id', 'name', 'network_address'];
        $sort = in_array($request->query('sort'), $allowedSorts, true)
            ? $request->query('sort')
            : 'id';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->query('record_per_page', 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300, 350, 400, 450, 500], true)) {
            $perPage = 50;
        }

        $search = trim((string) $request->query('search', ''));

        $advancedFilters = $request->input('filters', []);
        $subnets = $this->subnets->paginate($search, $advancedFilters, $sort, $dir, $perPage);

        return view('subnets.index', [
            'subnets'        => $subnets,
            'sort'           => $sort,
            'dir'            => $dir,
            'perPage'        => $perPage,
            'search'         => $search,
            'filterFields'   => SubnetFilter::fields(),
            'currentFilters' => $advancedFilters,
            'canNew'         => $this->can('new_all'),
            'canEdit'        => $this->can('edit_all'),
            'canDelete'      => $this->can('delete_all'),
            'showDhcp'       => $this->can('view_all', 'dhcp'),
            'showDns'        => $this->can('view_all', 'dns'),
            'showQos'        => $this->can('view_all', 'qos'),
        ]);
    }

    public function show(int $id, LineIdRepository $lineIds)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $subnet = $this->subnets->findWithIpAddressesOrFail($id);

        // Sdílené line-idy: iface bez per-port mapování (line_ids), jehož MAC je
        // v line_id_seen na circuit-id, který vidí víc MAC → sdílený (typicky
        // bytovka za jedním ONT bez L2 switche s option82). Odlišíme ve výpisu
        // žlutě „sdílený" od červené „chybí" (fakt nenaučeno). Viz SharedCircuitDetector (doména Access).
        $macs = $subnet->ipAddresses
            ->map(fn($ip) => $ip->iface?->mac)
            ->filter()
            ->map(fn($m) => strtoupper($m))
            ->unique()
            ->values()
            ->all();

        return view('subnets.show', [
            'subnet'        => $subnet,
            'sharedLineIds' => $lineIds->sharedCircuitsByMac($macs),
            'canEdit'       => $this->can('edit_all'),
            'canDelete'     => $this->can('delete_all'),
            'showDhcp'      => $this->can('view_all', 'dhcp'),
            'showDns'       => $this->can('view_all', 'dns'),
            'showQos'       => $this->can('view_all', 'qos'),
        ]);
    }

    public function create()
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        return view('subnets.create', [
            'canSetDhcp' => $this->can('new_all', 'dhcp'),
            'canSetDns'  => $this->can('new_all', 'dns'),
            'canSetQos'  => $this->can('new_all', 'qos'),
        ]);
    }

    public function store(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $data = $this->validateSubnet($request, null, 'new_all');

        $subnet = $this->subnets->create($data);

        session()->flash('success', 'Subnet byl úspěšně přidán.');
        return redirect()->route('subnets.show', $subnet->id);
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $subnet = $this->subnets->findOrFail($id);

        return view('subnets.edit', [
            'subnet'     => $subnet,
            'canSetDhcp' => $this->can('edit_all', 'dhcp'),
            'canSetDns'  => $this->can('edit_all', 'dns'),
            'canSetQos'  => $this->can('edit_all', 'qos'),
        ]);
    }

    public function update(Request $request, int $id, UpdateSubnetAction $update)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $subnet = $this->subnets->findOrFail($id);

        $data = $this->validateSubnet($request, $id, 'edit_all');

        $update->handle($subnet, $data);

        session()->flash('success', 'Subnet byl úspěšně upraven.');
        return redirect()->route('subnets.show', $id);
    }

    public function destroy(int $id)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $subnet = $this->subnets->findOrFail($id);

        if ($this->subnets->hasIpAddresses($subnet)) {
            session()->flash('error', 'Subnet nelze smazat, obsahuje IP adresy.');
            return redirect()->back();
        }
        // smazání by kaskádou (FK) smazalo i žádosti o připojení
        if ($count = $this->subnets->connectionRequestCount($subnet)) {
            session()->flash('error', "Subnet nelze smazat, odkazuje na něj {$count} žádostí o připojení.");
            return redirect()->back();
        }

        $this->subnets->delete($subnet);

        session()->flash('success', 'Subnet byl úspěšně smazán.');
        return redirect()->route('subnets.index');
    }

    // -------------------------------------------------------------------------

    private function validateSubnet(Request $request, ?int $excludeId = null, string $action = 'edit_all'): array
    {
        $data = $request->validate([
            'name'            => 'nullable|string|max:254',
            'network_address' => [
                'required',
                'string',
                'max:15',
                'regex:/^\d{1,3}(\.\d{1,3}){3}$/',
                Rule::unique('subnets')->where('netmask', $request->input('netmask'))
                    ->ignore($excludeId),
            ],
            'netmask'         => [
                'required',
                'string',
                'max:15',
                'regex:/^(255|254|252|248|240|224|192|128|0)(\.(255|254|252|248|240|224|192|128|0)){3}$/',
            ],
            'dhcp'         => 'nullable|boolean',
            'dns'          => 'nullable|boolean',
            'qos'          => 'nullable|boolean',
            'ipoe'         => 'nullable|boolean',
            'redirect'     => 'nullable|boolean',
        ], [
            'network_address.regex'  => 'Neplatný formát síťové adresy.',
            'network_address.unique' => 'Tento subnet (síť + maska) již existuje.',
            'netmask.regex'          => 'Neplatný formát masky sítě.',
        ]);

        // Verify network address is correct for the given netmask
        $network = $data['network_address'];
        $mask    = $data['netmask'];

        // maska souvislá (255.0.255.0 regex pustí)
        $hostBits = ~ip2long($mask) & 0xFFFFFFFF;
        if ((($hostBits + 1) & $hostBits) !== 0) {
            back()->withInput()->withErrors(['netmask' => 'Neplatný formát masky sítě.'])->throwResponse();
        }

        if (ip2long($network) === false || (ip2long($network) & ip2long($mask)) !== ip2long($network)) {
            return back()->withInput()
                ->withErrors(['network_address' => 'Síťová adresa neodpovídá zadané masce.'])
                ->throwResponse();
        }

        // změna sítě / masky nesmí nechat existující IP mimo rozsah
        if ($excludeId && ($outside = $this->subnets->ipAddressesOutside($excludeId, $network, $mask))) {
            back()->withInput()->withErrors(['netmask' => 'Po změně by ' . count($outside) . ' IP adres leželo mimo podsíť (např. ' . implode(', ', array_slice($outside, 0, 3)) . ').'])->throwResponse();
        }

        // Jemná práva: dhcp/dns/qos smí nastavit jen kdo má odpovídající právo
        // ($action = new_all při store, edit_all při update) — stejně jako view
        // podmiňuje canSetDhcp/Dns/Qos. Bez práva pole z $data odstranit →
        // create nastaví default, update ponechá stávající hodnotu beze změny.
        foreach (['dhcp', 'dns', 'qos'] as $flag) {
            if (!$this->can($action, $flag)) {
                unset($data[$flag]);
            }
        }
        // IPoE (line-id mód) se řídí stejným právem jako DHCP.
        if (array_key_exists('ipoe', $data) && !$this->can($action, 'dhcp')) {
            unset($data['ipoe']);
        }

        return $data;
    }
}
