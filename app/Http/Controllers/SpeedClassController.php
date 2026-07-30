<?php

namespace App\Http\Controllers;

use App\Models\SpeedClass;
use Illuminate\Http\Request;

class SpeedClassController extends Controller
{
    private function can(string $action): bool
    {
        return $this->aclCheck($action, 'Speed_classes_Controller', 'speed_classes');
    }

    /** Ceníková cena: prázdné/nečíselné → null, jinak float (přijímá i desetinnou čárku). */
    private function normalizePrice(?string $raw): ?float
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;
        $norm = str_replace(',', '.', $raw);
        return is_numeric($norm) ? (float) $norm : null;
    }

    public function index()
    {
        $speedClasses = SpeedClass::withCount('members')->get();
        $canEdit = $this->can('edit_all');
        $canNew  = $this->can('new_all');
        $canDel  = $this->can('delete_all');

        return view('speed_classes.index', compact('speedClasses', 'canEdit', 'canNew', 'canDel'));
    }

    public function create()
    {
        if (!$this->can('new_all'))
            abort(403);

        return view('speed_classes.create');
    }

    public function store(Request $request)
    {
        if (!$this->can('new_all'))
            abort(403);

        $validated = $request->validate([
            'name'     => 'required|string|max:50|unique:speed_classes,name',
            'qos_ceil' => 'required|string|max:30',
            'qos_rate' => 'required|string|max:30',
            'price'    => 'nullable|string|max:20',
        ], [
            'name.required'     => 'Jméno je povinné.',
            'name.unique'       => 'Třída rychlosti s tímto jménem již existuje.',
            'qos_ceil.required' => 'Max. rychlost (ceil) je povinná.',
            'qos_rate.required' => 'Min. rychlost (rate) je povinná.',
        ]);

        [$d_ceil, $u_ceil] = SpeedClass::parseSlash($validated['qos_ceil']);
        [$d_rate, $u_rate] = SpeedClass::parseSlash($validated['qos_rate']);

        SpeedClass::create([
            'name'                   => $validated['name'],
            'd_ceil'                 => $d_ceil,
            'u_ceil'                 => $u_ceil,
            'd_rate'                 => $d_rate,
            'u_rate'                 => $u_rate,
            'price'                  => $this->normalizePrice($request->input('price')),
            'regular_member_default' => false,
            'applicant_default'      => false,
        ]);

        return redirect()->route('speed_classes.index')
            ->with('success', 'Třída rychlosti byla přidána.');
    }

    public function show($id)
    {
        return redirect()->route('speed_classes.index');
    }

    public function edit($id)
    {
        if (!$this->can('edit_all'))
            abort(403);

        $speedClass = SpeedClass::findOrFail($id);

        return view('speed_classes.edit', compact('speedClass'));
    }

    public function update(Request $request, $id)
    {
        if (!$this->can('edit_all'))
            abort(403);

        $speedClass = SpeedClass::findOrFail($id);

        $validated = $request->validate([
            'name'     => 'required|string|max:50|unique:speed_classes,name,' . $id,
            'qos_ceil' => 'required|string|max:30',
            'qos_rate' => 'required|string|max:30',
            'price'    => 'nullable|string|max:20',
        ], [
            'name.required'     => 'Jméno je povinné.',
            'name.unique'       => 'Třída rychlosti s tímto jménem již existuje.',
            'qos_ceil.required' => 'Max. rychlost (ceil) je povinná.',
            'qos_rate.required' => 'Min. rychlost (rate) je povinná.',
        ]);

        [$d_ceil, $u_ceil] = SpeedClass::parseSlash($validated['qos_ceil']);
        [$d_rate, $u_rate] = SpeedClass::parseSlash($validated['qos_rate']);

        $speedClass->update([
            'name'   => $validated['name'],
            'd_ceil' => $d_ceil,
            'u_ceil' => $u_ceil,
            'd_rate' => $d_rate,
            'u_rate' => $u_rate,
            'price'  => $this->normalizePrice($request->input('price')),
        ]);

        return redirect()->route('speed_classes.index')
            ->with('success', 'Třída rychlosti byla upravena.');
    }

    public function destroy($id)
    {
        if (!$this->can('delete_all'))
            abort(403);

        $speedClass = SpeedClass::withCount('members')->findOrFail($id);

        if ($speedClass->members_count > 0) {
            return back()->with('error', "Třídu nelze smazat — je přiřazena {$speedClass->members_count} členům.");
        }

        $speedClass->delete();

        return redirect()->route('speed_classes.index')
            ->with('success', 'Třída rychlosti byla smazána.');
    }

    public function setDefault(Request $request, $id, $type)
    {
        if (!$this->can('edit_all'))
            abort(403);

        if (!in_array($type, ['member', 'applicant'], true))
            abort(404);

        $speedClass = SpeedClass::findOrFail($id);
        $col = $type === 'member' ? 'regular_member_default' : 'applicant_default';

        if ($speedClass->$col) {
            // currently default → unset
            $speedClass->update([$col => false]);
        } else {
            // set this as default, unset all others
            SpeedClass::where('id', '!=', $id)->update([$col => false]);
            $speedClass->update([$col => true]);
        }

        return back()->with('success', 'Výchozí třída byla nastavena.');
    }
}
