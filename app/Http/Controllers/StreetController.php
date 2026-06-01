<?php

namespace App\Http\Controllers;

use App\Models\Street;
use App\Models\Town;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StreetController extends Controller
{
    private const ACL_SECTION = 'Address_points_Controller';
    private const ACL_VALUE   = 'street';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index(Request $request)
    {
        $allowedSorts = ['id', 'street', 'town_id'];
        $sort = in_array($request->query('sort'), $allowedSorts, true)
            ? $request->query('sort')
            : 'id';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->query('record_per_page', 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300, 350, 400, 450, 500], true)) {
            $perPage = 50;
        }

        $streets = Street::with('town')
            ->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();

        return view('streets.index', [
            'streets'   => $streets,
            'sort'      => $sort,
            'dir'       => $dir,
            'perPage'   => $perPage,
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

        $street = Street::with('town')->find($id);
        if (!$street) {
            abort(404);
        }

        $apTotal  = DB::table('address_points')->where('street_id', $id)->count();
        $apOrphan = DB::table('address_points')->where('street_id', $id)
            ->whereNotExists(fn($q) => $q->from('members')->whereColumn('members.address_point_id', 'address_points.id'))
            ->whereNotExists(fn($q) => $q->from('devices')->whereColumn('devices.address_point_id', 'address_points.id'))
            ->whereNotExists(fn($q) => $q->from('members_domiciles')->whereColumn('members_domiciles.address_point_id', 'address_points.id'))
            ->count();
        $memberCount = DB::table('members')
            ->join('address_points as ap', 'ap.id', '=', 'members.address_point_id')
            ->where('ap.street_id', $id)
            ->count();

        return view('streets.show', [
            'street'      => $street,
            'apTotal'     => $apTotal,
            'apOrphan'    => $apOrphan,
            'memberCount' => $memberCount,
            'canEdit'     => $this->can('edit_all'),
        ]);
    }

    public function create()
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $towns = Town::orderBy('town')->orderBy('quarter')->orderBy('zip_code')->get();

        return view('streets.create', ['towns' => $towns]);
    }

    public function store(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $data = $request->validate([
            'town_id' => 'required|integer|exists:towns,id',
            'street'  => [
                'required', 'string', 'max:50',
                Rule::unique('streets')->where(fn($q) => $q->where('town_id', $request->input('town_id'))),
            ],
        ], ['street.unique' => 'Tato ulice v daném městě již existuje.']);

        Street::create($data);

        session()->flash('success', 'Ulice byla úspěšně přidána.');

        return redirect()->route('streets.index');
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $street = Street::find($id);
        if (!$street) {
            abort(404);
        }

        $towns = Town::orderBy('town')->orderBy('quarter')->orderBy('zip_code')->get();

        return view('streets.edit', ['street' => $street, 'towns' => $towns]);
    }

    public function update(Request $request, int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $street = Street::find($id);
        if (!$street) {
            abort(404);
        }

        $data = $request->validate([
            'town_id' => 'required|integer|exists:towns,id',
            'street'  => [
                'required', 'string', 'max:50',
                Rule::unique('streets')->where(fn($q) => $q->where('town_id', $request->input('town_id')))->ignore($id),
            ],
        ], ['street.unique' => 'Tato ulice v daném městě již existuje.']);

        $street->update($data);

        session()->flash('success', 'Ulice byla úspěšně upravena.');

        return redirect()->route('streets.index');
    }

    public function destroy(int $id)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $street = Street::find($id);
        if (!$street) {
            abort(404);
        }

        if ($street->addressPoints()->exists()) {
            session()->flash('error', 'Ulici nelze smazat, má přiřazené adresní body.');
            return redirect()->back();
        }

        $street->delete();

        session()->flash('success', 'Ulice byla úspěšně smazána.');

        return redirect()->route('streets.index');
    }
}
