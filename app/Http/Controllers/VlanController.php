<?php

namespace App\Http\Controllers;

use App\Domains\Network\Repositories\VlanRepository;
use App\Models\Vlan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VlanController extends Controller
{
    private const ACL_SECTION = 'Vlans_Controller';
    private const ACL_VALUE   = 'vlan';

    public function __construct(private VlanRepository $vlans)
    {
    }

    private function can(string $action, string $section = self::ACL_SECTION, string $value = self::ACL_VALUE): bool
    {
        return $this->aclCheck($action, $section, $value);
    }

    public function index(Request $request)
    {
        $allowedSorts = ['id', 'name', 'tag_802_1q'];
        $sort = in_array($request->query('sort'), $allowedSorts, true)
            ? $request->query('sort')
            : 'tag_802_1q';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->query('record_per_page', 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300, 350, 400, 450, 500], true)) {
            $perPage = 50;
        }

        $q = trim((string) $request->query('q', ''));

        return view('vlans.index', [
            'vlans'     => $this->vlans->paginate($q, $sort, $dir, $perPage),
            'sort'      => $sort,
            'dir'       => $dir,
            'perPage'   => $perPage,
            'q'         => $q,
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

        $vlan = $this->vlans->findWithIfacesOrFail($id);

        return view('vlans.show', [
            'vlan'           => $vlan,
            'canEdit'        => $this->can('edit_all'),
            'canDelete'      => $this->can('delete_all'),
            'canViewDevices' => $this->can('view_all', 'Devices_Controller', 'devices'),
        ]);
    }

    public function create()
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        return view('vlans.create');
    }

    public function store(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $data = $this->validateVlan($request);
        $vlan = $this->vlans->create($data);

        session()->flash('success', 'VLAN byla úspěšně přidána.');
        return redirect()->route('vlans.show', $vlan->id);
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $vlan = $this->vlans->findOrFail($id);

        return view('vlans.edit', ['vlan' => $vlan]);
    }

    public function update(Request $request, int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $vlan = $this->vlans->findOrFail($id);

        $data = $this->validateVlan($request, $id);
        $this->vlans->update($vlan, $data);

        session()->flash('success', 'VLAN byla úspěšně upravena.');
        return redirect()->route('vlans.show', $id);
    }

    public function destroy(int $id)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $vlan = $this->vlans->findOrFail($id);

        if ($vlan->tag_802_1q === Vlan::DEFAULT_VLAN_TAG) {
            session()->flash('error', 'Výchozí VLAN nelze smazat.');
            return redirect()->back();
        }

        if ($this->vlans->hasIfaces($vlan)) {
            session()->flash('error', 'VLAN nelze smazat, má přiřazená rozhraní.');
            return redirect()->back();
        }

        $this->vlans->delete($vlan);

        session()->flash('success', 'VLAN byla úspěšně smazána.');
        return redirect()->route('vlans.index');
    }

    // -------------------------------------------------------------------------

    private function validateVlan(Request $request, ?int $excludeId = null): array
    {
        return $request->validate([
            'name'       => 'nullable|string|max:254',
            'tag_802_1q' => [
                'required',
                'integer',
                'between:1,4094',
                Rule::unique('vlans', 'tag_802_1q')->ignore($excludeId),
            ],
            'comment'    => 'nullable|string|max:254',
        ], [
            'tag_802_1q.required' => 'Pole 802.1Q tag je povinné.',
            'tag_802_1q.integer'  => '802.1Q tag musí být celé číslo.',
            'tag_802_1q.between'  => '802.1Q tag musí být v rozsahu 1–4094.',
            'tag_802_1q.unique'   => 'Tento 802.1Q tag je již použit.',
        ]);
    }
}
