<?php

namespace App\Http\Controllers;

use App\Models\EnumType;
use App\Models\EnumTypeName;
use App\Services\AclService;
use Illuminate\Http\Request;

class EnumTypeController extends Controller
{
    private const ACL_SECTION = 'Settings_Controller';
    private const ACL_VALUE   = 'enum_types';

    public function __construct(private AclService $acl) {}

    private function can(string $action): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index()
    {
        abort_unless($this->can('view_all'), 403);

        $groups = EnumTypeName::with(['enumTypes' => function ($q) {
            $q->orderBy('value');
        }])->orderBy('type_name')->get();

        return view('enum_types.index', compact('groups'));
    }

    public function create()
    {
        abort_unless($this->can('new_all'), 403);
        $typeNames = EnumTypeName::orderBy('type_name')->get();
        return view('enum_types.create', compact('typeNames'));
    }

    public function store(Request $request)
    {
        abort_unless($this->can('new_all'), 403);

        $validated = $request->validate([
            'type_id' => 'required|integer|exists:enum_type_names,id',
            'value'   => 'required|string|max:100',
        ]);

        EnumType::create($validated);

        return redirect()->route('enum-types.index')
            ->with('success', 'Typ byl přidán.');
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);
        $enumType  = EnumType::with('typeName')->findOrFail($id);
        $typeNames = EnumTypeName::orderBy('type_name')->get();
        return view('enum_types.edit', compact('enumType', 'typeNames'));
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $enumType = EnumType::findOrFail($id);

        if ($enumType->read_only) {
            return back()->with('error', 'Tento typ je systémový a nelze ho upravit.');
        }

        $validated = $request->validate([
            'type_id' => 'required|integer|exists:enum_type_names,id',
            'value'   => 'required|string|max:100',
        ]);

        $enumType->update($validated);

        return redirect()->route('enum-types.index')
            ->with('success', 'Typ byl upraven.');
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);

        $enumType = EnumType::findOrFail($id);

        if ($enumType->read_only) {
            return back()->with('error', 'Tento typ je systémový a nelze ho smazat.');
        }

        $enumType->delete();

        return redirect()->route('enum-types.index')
            ->with('success', 'Typ byl smazán.');
    }
}
