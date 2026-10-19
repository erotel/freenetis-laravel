<?php

namespace App\Http\Controllers;

use App\Domains\Members\Repositories\EnumTypeRepository;
use Illuminate\Http\Request;

class EnumTypeController extends Controller
{
    private const ACL_SECTION = 'Enum_types_Controller';
    private const ACL_VALUE   = 'enum_types';

    private const RULES = [
        'type_id' => 'required|integer|exists:enum_type_names,id',
        'value'   => 'required|string|max:100',
    ];

    public function __construct(private EnumTypeRepository $types)
    {
    }

    public function index()
    {
        return view('enum_types.index', ['groups' => $this->types->groups()]);
    }

    public function create()
    {
        abort_unless($this->aclCheck('new_all', self::ACL_SECTION, self::ACL_VALUE), 403);
        return view('enum_types.create', ['typeNames' => $this->types->typeNames()]);
    }

    public function store(Request $request)
    {
        abort_unless($this->aclCheck('new_all', self::ACL_SECTION, self::ACL_VALUE), 403);

        $this->types->create($request->validate(self::RULES));

        return redirect()->route('enum-types.index')
            ->with('success', 'Typ byl přidán.');
    }

    public function edit(int $id)
    {
        abort_unless($this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_VALUE), 403);
        return view('enum_types.edit', [
            'enumType'  => $this->types->findWithTypeNameOrFail($id),
            'typeNames' => $this->types->typeNames(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_VALUE), 403);

        $enumType = $this->types->findOrFail($id);

        if ($enumType->read_only) {
            return back()->with('error', 'Tento typ je systémový a nelze ho upravit.');
        }

        $data = $request->validate(self::RULES);
        if ((int) $data['type_id'] !== (int) $enumType->type_id && ($used = $this->types->usageCount($id))) {
            return back()->withInput()->with('error', "Typ používá {$used} záznamů — nelze ho přesunout do jiné skupiny.");
        }

        $this->types->update($enumType, $data);

        return redirect()->route('enum-types.index')
            ->with('success', 'Typ byl upraven.');
    }

    public function destroy(int $id)
    {
        abort_unless($this->aclCheck('delete_all', self::ACL_SECTION, self::ACL_VALUE), 403);

        $enumType = $this->types->findOrFail($id);

        if ($enumType->read_only) {
            return back()->with('error', 'Tento typ je systémový a nelze ho smazat.');
        }

        if ($used = $this->types->usageCount($id)) {
            return back()->with('error', "Typ používá {$used} záznamů — nelze ho smazat.");
        }

        $this->types->delete($enumType);

        return redirect()->route('enum-types.index')
            ->with('success', 'Typ byl smazán.');
    }
}
