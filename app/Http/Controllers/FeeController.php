<?php

namespace App\Http\Controllers;

use App\Models\Fee;
use App\Services\AclService;
use Illuminate\Http\Request;

class FeeController extends Controller
{
    private const ACL_SECTION = 'Fees_Controller';
    private const ACL_VALUE   = 'fees';

    public function __construct(private AclService $acl) {}

    private function can(string $action): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index(Request $request)
    {
        abort_unless($this->can('view_all'), 403);

        $sort = in_array($request->sort, ['id', 'type_id', 'name', 'fee', 'from', 'to']) ? $request->sort : 'type_id';
        $dir  = $request->dir === 'desc' ? 'desc' : 'asc';

        $fees = Fee::with('enumType')->orderBy($sort, $dir)->paginate(50)->withQueryString();

        return view('fees.index', [
            'fees'      => $fees,
            'sort'      => $sort,
            'dir'       => $dir,
            'canNew'    => $this->can('new_all'),
            'canEdit'   => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
        ]);
    }

    public function create()
    {
        abort_unless($this->can('new_all'), 403);

        return view('fees.create', ['typeLabels' => Fee::typeLabels()]);
    }

    public function store(Request $request)
    {
        abort_unless($this->can('new_all'), 403);

        $data = $request->validate([
            'type_id' => 'required|integer|in:' . implode(',', array_keys(Fee::typeLabels())),
            'name'    => 'nullable|string|max:100',
            'fee'     => 'required|numeric|min:0',
            'from'    => 'required|date',
            'to'      => 'required|date|after_or_equal:from',
        ]);

        Fee::create($data);

        session()->flash('success', 'Tarif byl úspěšně přidán.');
        return redirect()->route('fees.index');
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $fee = Fee::findOrFail($id);
        abort_if($fee->readonly, 403, 'Tento tarif je systémový a nelze ho upravit.');

        return view('fees.edit', ['fee' => $fee, 'typeLabels' => Fee::typeLabels()]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $fee = Fee::findOrFail($id);
        abort_if($fee->readonly, 403, 'Tento tarif je systémový a nelze ho upravit.');

        $data = $request->validate([
            'type_id' => 'required|integer|in:' . implode(',', array_keys(Fee::typeLabels())),
            'name'    => 'nullable|string|max:100',
            'fee'     => 'required|numeric|min:0',
            'from'    => 'required|date',
            'to'      => 'required|date|after_or_equal:from',
        ]);

        $fee->update($data);

        session()->flash('success', 'Tarif byl úspěšně upraven.');
        return redirect()->route('fees.index');
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);

        $fee = Fee::findOrFail($id);

        if ($fee->readonly) {
            session()->flash('error', 'Tento tarif je systémový a nelze ho smazat.');
            return redirect()->back();
        }

        if ($fee->memberFees()->exists()) {
            session()->flash('error', 'Tarif nelze smazat, je přiřazen členům.');
            return redirect()->back();
        }

        $fee->delete();

        session()->flash('success', 'Tarif byl úspěšně smazán.');
        return redirect()->route('fees.index');
    }
}
