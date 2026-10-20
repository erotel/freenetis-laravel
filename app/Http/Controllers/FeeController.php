<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Repositories\FeeCatalogRepository;
use App\Models\Fee;
use Illuminate\Http\Request;

/** Ceník poplatků / tarifů; data v doméně Billing. */
class FeeController extends Controller
{
    public function __construct(private FeeCatalogRepository $fees) {}

    private function rules(): array
    {
        return [
            'type_id' => 'required|integer|in:' . implode(',', array_keys(Fee::typeLabels())),
            'name'    => 'nullable|string|max:100',
            'fee'     => 'required|numeric|min:0',
            'from'    => 'required|date',
            'to'      => 'required|date|after_or_equal:from',
        ];
    }

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

        $fees          = $this->fees->paginate($sort, $dir, $showArchived, 50);
        $archivedCount = $this->fees->archivedCount();

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

        $fee     = $this->fees->findWithTypeOrFail($id);
        $members = $this->fees->activeMembers($id);

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

        $data = $request->validate($this->rules());

        $this->fees->create($data);

        session()->flash('success', 'Tarif byl úspěšně přidán.');
        return redirect()->route('fees.index');
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $fee = $this->fees->findOrFail($id);
        abort_if($fee->readonly, 403, 'Tento tarif je systémový a nelze ho upravit.');

        return view('fees.edit', ['fee' => $fee, 'typeLabels' => Fee::typeLabels()]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $fee = $this->fees->findOrFail($id);
        abort_if($fee->readonly, 403, 'Tento tarif je systémový a nelze ho upravit.');

        $data = $request->validate($this->rules());

        $this->fees->update($fee, $data);

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

        $fee = $this->fees->findOrFail($id);

        // Pojistka: archivovat lze jen tarif bez aktivního přiřazení (tlačítko se
        // u aktivních ani nezobrazí, tohle chrání proti obejití). Obnovit lze vždy.
        if (!$fee->archived && $this->fees->hasActiveAssignments($fee)) {
            session()->flash('error', 'Tarif má aktivní přiřazení, nelze ho archivovat. Nejdřív ho odeberte členům.');
            return redirect()->back();
        }

        $this->fees->update($fee, ['archived' => !$fee->archived]);

        session()->flash('success', $fee->archived
            ? 'Tarif byl archivován (skryt z nabídky). Historie zůstává zachována.'
            : 'Tarif byl obnoven do nabídky.');

        return redirect()->back();
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);

        $fee = $this->fees->findOrFail($id);

        if ($fee->readonly) {
            session()->flash('error', 'Tento tarif je systémový a nelze ho smazat.');
            return redirect()->back();
        }

        if ($this->fees->isAssigned($fee)) {
            session()->flash('error', 'Tarif nelze smazat, je přiřazen členům.');
            return redirect()->back();
        }

        $this->fees->delete($fee);

        session()->flash('success', 'Tarif byl úspěšně smazán.');
        return redirect()->route('fees.index');
    }
}
