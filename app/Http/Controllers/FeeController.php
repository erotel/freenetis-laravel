<?php

namespace App\Http\Controllers;

use App\Models\Fee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeeController extends Controller
{
    private const ACL_SECTION = 'Fees_Controller';
    private const ACL_VALUE   = 'fees';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index(Request $request)
    {
        $sort = in_array($request->sort, ['id', 'type_id', 'name', 'fee', 'from', 'to']) ? $request->sort : 'type_id';
        $dir  = $request->dir === 'desc' ? 'desc' : 'asc';

        // Archivované tarify jsou ve výchozím výpisu skryté (?archived=1 je zobrazí).
        $showArchived = $request->boolean('archived');

        $fees = Fee::with('enumType')
            ->when(!$showArchived, fn ($q) => $q->where('archived', false))
            ->orderBy($sort, $dir)
            ->paginate(50)
            ->withQueryString();

        $archivedCount = Fee::where('archived', true)->count();

        // Počet aktivních přiřazení u každého tarifu (klikací → výpis členů).
        // U tarifu s aktivními přiřazeními se navíc nedá archivovat.
        $today = now()->toDateString();
        $fees->getCollection()->each(function ($fee) use ($today) {
            $fee->active_count = $fee->activeAssignmentsCount($today);
            $fee->has_active   = $fee->active_count > 0;
        });

        return view('fees.index', [
            'fees'          => $fees,
            'sort'          => $sort,
            'dir'           => $dir,
            'showArchived'  => $showArchived,
            'archivedCount' => $archivedCount,
            'canNew'        => $this->can('new_all'),
            'canEdit'       => $this->can('edit_all'),
            'canDelete'     => $this->can('delete_all'),
        ]);
    }

    /** Výpis členů, kteří mají daný tarif aktivně přiřazený (proklik z počtu). */
    public function members(int $id)
    {
        abort_unless($this->can('view_all'), 403);

        $fee   = Fee::with('enumType')->findOrFail($id);
        $today = now()->toDateString();

        $members = DB::table('members_fees as mf')
            ->join('members as m', 'm.id', '=', 'mf.member_id')
            ->where('mf.fee_id', $id)
            ->where('mf.activation_date', '<=', $today)
            ->where('mf.deactivation_date', '>=', $today)
            ->orderBy('m.name')
            ->get(['m.id as member_id', 'm.name', 'm.type', 'mf.activation_date', 'mf.deactivation_date', 'mf.comment']);

        return view('fees.members', [
            'fee'     => $fee,
            'members' => $members,
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

        Fee::create(array_merge($data, ['readonly' => 0]));

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

    /**
     * Archivovat / obnovit tarif — skryje ho z nabídky při přiřazování poplatku
     * členovi, aniž by se cokoli mazalo (historie v members_fees zůstává).
     * Bezpečná vratná alternativa k mazání legacy tarifů.
     */
    public function toggleArchive(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $fee = Fee::findOrFail($id);

        // Pojistka: archivovat lze jen tarif bez aktivního přiřazení (tlačítko se
        // u aktivních ani nezobrazí, tohle chrání proti obejití). Obnovit lze vždy.
        if (!$fee->archived && $fee->hasActiveAssignments()) {
            session()->flash('error', 'Tarif má aktivní přiřazení, nelze ho archivovat. Nejdřív ho odeberte členům.');
            return redirect()->back();
        }

        $fee->update(['archived' => !$fee->archived]);

        session()->flash('success', $fee->archived
            ? 'Tarif byl archivován (skryt z nabídky). Historie zůstává zachována.'
            : 'Tarif byl obnoven do nabídky.');

        return redirect()->back();
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
