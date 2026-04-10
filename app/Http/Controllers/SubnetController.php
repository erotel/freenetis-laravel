<?php

namespace App\Http\Controllers;

use App\Models\Subnet;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubnetController extends Controller
{
    private const ACL_SECTION = 'Subnets_Controller';
    private const ACL_VALUE   = 'subnet';

    private function can(string $action, string $value = self::ACL_VALUE): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, $value);
    }

    public function index(Request $request)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

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

        $query = Subnet::withCount('ipAddresses');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('network_address', 'like', "%{$search}%");
            });
        }

        $subnets = $query->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();

        return view('subnets.index', [
            'subnets'     => $subnets,
            'sort'        => $sort,
            'dir'         => $dir,
            'perPage'     => $perPage,
            'search'      => $search,
            'canNew'      => $this->can('new_all'),
            'canEdit'     => $this->can('edit_all'),
            'canDelete'   => $this->can('delete_all'),
            'showDhcp'    => $this->can('view_all', 'dhcp'),
            'showDns'     => $this->can('view_all', 'dns'),
            'showQos'     => $this->can('view_all', 'qos'),
        ]);
    }

    public function show(int $id)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $subnet = Subnet::with(['ipAddresses.member', 'ipAddresses.iface.device'])->find($id);
        if (!$subnet) {
            abort(404);
        }

        return view('subnets.show', [
            'subnet'    => $subnet,
            'canEdit'   => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
            'showDhcp'  => $this->can('view_all', 'dhcp'),
            'showDns'   => $this->can('view_all', 'dns'),
            'showQos'   => $this->can('view_all', 'qos'),
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

        $data = $this->validateSubnet($request);

        $subnet = Subnet::create($data);

        session()->flash('success', 'Subnet byl úspěšně přidán.');
        return redirect()->route('subnets.show', $subnet->id);
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $subnet = Subnet::find($id);
        if (!$subnet) {
            abort(404);
        }

        return view('subnets.edit', [
            'subnet'     => $subnet,
            'canSetDhcp' => $this->can('edit_all', 'dhcp'),
            'canSetDns'  => $this->can('edit_all', 'dns'),
            'canSetQos'  => $this->can('edit_all', 'qos'),
        ]);
    }

    public function update(Request $request, int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $subnet = Subnet::find($id);
        if (!$subnet) {
            abort(404);
        }

        $data = $this->validateSubnet($request, $id);
        $subnet->update($data);

        session()->flash('success', 'Subnet byl úspěšně upraven.');
        return redirect()->route('subnets.show', $id);
    }

    public function destroy(int $id)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $subnet = Subnet::find($id);
        if (!$subnet) {
            abort(404);
        }

        if ($subnet->ipAddresses()->exists()) {
            session()->flash('error', 'Subnet nelze smazat, obsahuje IP adresy.');
            return redirect()->back();
        }

        $subnet->delete();

        session()->flash('success', 'Subnet byl úspěšně smazán.');
        return redirect()->route('subnets.index');
    }

    // -------------------------------------------------------------------------

    private function validateSubnet(Request $request, ?int $excludeId = null): array
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
            'redirect'     => 'nullable|boolean',
        ], [
            'network_address.regex'  => 'Neplatný formát síťové adresy.',
            'network_address.unique' => 'Tento subnet (síť + maska) již existuje.',
            'netmask.regex'          => 'Neplatný formát masky sítě.',
        ]);

        // Verify network address is correct for the given netmask
        $network = $data['network_address'];
        $mask    = $data['netmask'];

        if ((ip2long($network) & ip2long($mask)) !== ip2long($network)) {
            return back()->withInput()
                ->withErrors(['network_address' => 'Síťová adresa neodpovídá zadané masce.'])
                ->throwResponse();
        }

        return $data;
    }
}
