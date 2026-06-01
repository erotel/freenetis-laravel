<?php

namespace App\Http\Controllers;

use App\Models\Town;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TownController extends Controller
{
    private const ACL_SECTION = 'Address_points_Controller';
    private const ACL_VALUE   = 'town';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index(Request $request)
    {
        $allowedSorts = ['id', 'town', 'quarter', 'zip_code'];
        $sort = in_array($request->query('sort'), $allowedSorts, true)
            ? $request->query('sort')
            : 'id';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->query('record_per_page', 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300, 350, 400, 450, 500], true)) {
            $perPage = 50;
        }

        $towns = Town::orderBy($sort, $dir)->paginate($perPage)->withQueryString();

        return view('towns.index', [
            'towns'   => $towns,
            'sort'    => $sort,
            'dir'     => $dir,
            'perPage' => $perPage,
            'canNew'  => $this->can('new_all'),
            'canEdit' => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
        ]);
    }

    public function show(int $id)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

        $town = Town::find($id);
        if (!$town) {
            abort(404);
        }

        $streets = $town->streets()->orderBy('street')->get();

        // AP celkem + počet, kolik z nich obsazuje aspoň jeden člen/zařízení/domicil.
        // „Prázdné AP" = AP v této obci bez jakékoli reference (kandidáti na úklid).
        $apTotal  = DB::table('address_points')->where('town_id', $id)->count();
        $apOrphan = DB::table('address_points')->where('town_id', $id)
            ->whereNotExists(fn($q) => $q->from('members')->whereColumn('members.address_point_id', 'address_points.id'))
            ->whereNotExists(fn($q) => $q->from('devices')->whereColumn('devices.address_point_id', 'address_points.id'))
            ->whereNotExists(fn($q) => $q->from('members_domiciles')->whereColumn('members_domiciles.address_point_id', 'address_points.id'))
            ->count();
        $memberCount = DB::table('members')
            ->join('address_points as ap', 'ap.id', '=', 'members.address_point_id')
            ->where('ap.town_id', $id)
            ->count();

        return view('towns.show', [
            'town'        => $town,
            'streets'     => $streets,
            'apTotal'     => $apTotal,
            'apOrphan'    => $apOrphan,
            'memberCount' => $memberCount,
            'canEdit'   => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
        ]);
    }

    public function create()
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        return view('towns.create');
    }

    public function store(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $data = $request->validate([
            'town'     => 'required|string|max:50',
            'quarter'  => 'nullable|string|max:50',
            'zip_code' => ['required', 'string', 'size:5', 'regex:/^[0-9]+$/'],
        ]);

        $request->validate([
            'town' => \Illuminate\Validation\Rule::unique('towns')->where(function ($query) use ($data) {
                return $query
                    ->where('quarter', $data['quarter'] ?? null)
                    ->where('zip_code', $data['zip_code']);
            }),
        ], ['town.unique' => 'Tato kombinace města, čtvrti a PSČ již existuje.']);

        Town::create($data);

        session()->flash('success', 'Město bylo úspěšně přidáno.');

        return redirect()->route('towns.index');
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $town = Town::find($id);
        if (!$town) {
            abort(404);
        }

        return view('towns.edit', ['town' => $town]);
    }

    public function update(Request $request, int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $town = Town::find($id);
        if (!$town) {
            abort(404);
        }

        $data = $request->validate([
            'town'     => 'required|string|max:50',
            'quarter'  => 'nullable|string|max:50',
            'zip_code' => ['required', 'string', 'size:5', 'regex:/^[0-9]+$/'],
        ]);

        $request->validate([
            'town' => \Illuminate\Validation\Rule::unique('towns')->where(function ($query) use ($data) {
                return $query
                    ->where('quarter', $data['quarter'] ?? null)
                    ->where('zip_code', $data['zip_code']);
            })->ignore($id),
        ], ['town.unique' => 'Tato kombinace města, čtvrti a PSČ již existuje.']);

        $town->update($data);

        session()->flash('success', 'Město bylo úspěšně upraveno.');

        return redirect()->route('towns.index');
    }

    public function destroy(int $id)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $town = Town::find($id);
        if (!$town) {
            abort(404);
        }

        if ($town->addressPoints()->exists()) {
            return redirect()->back()
                ->with('error', 'Město nelze smazat, má přiřazené adresní body.');
        }

        $town->delete();

        session()->flash('success', 'Město bylo úspěšně smazáno.');

        return redirect()->route('towns.index');
    }
}
