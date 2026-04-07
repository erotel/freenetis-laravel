<?php

namespace App\Http\Controllers;

use App\Models\AroGroup;
use App\Services\AclService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AroGroupController extends Controller
{
    private const ACL_SECTION = 'Aro_groups_Controller';
    private const ACL_VALUE   = 'aro_groups';

    public function __construct(private AclService $acl) {}

    private function can(string $action): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index()
    {
        abort_unless($this->can('view_all'), 403);

        $groups = AroGroup::with('parent')->orderBy('lft')->get();
        $tree   = $this->buildTree($groups);

        return view('aro_groups.index', compact('groups', 'tree'));
    }

    public function show(int $id)
    {
        abort_unless($this->can('view_all'), 403);

        $group = AroGroup::with(['parent', 'children'])->findOrFail($id);

        // axo_map has (acl_id, section_value, value) — no axo_id FK, no join to axo needed
        // aco_map has (acl_id, value) where value is the action (view_all, edit_all, etc.)
        $aclRules = DB::table('aro_groups_map as agm')
            ->join('acl', 'acl.id', '=', 'agm.acl_id')
            ->join('axo_map', 'axo_map.acl_id', '=', 'acl.id')
            ->where('agm.group_id', $id)
            ->select(
                'acl.id as acl_id',
                'acl.note',
                'axo_map.section_value as section',
                'axo_map.value as resource'
            )
            ->orderBy('axo_map.section_value')
            ->orderBy('axo_map.value')
            ->get()
            ->groupBy('acl_id');

        $users = DB::table('groups_aro_map as gam')
            ->join('users', 'users.id', '=', 'gam.aro_id')
            ->where('gam.group_id', $id)
            ->select('users.id', 'users.login')
            ->orderBy('users.login')
            ->get();

        $assignedUserIds = $users->pluck('id')->toArray();
        $allUsers = DB::table('users')
            ->whereNotIn('id', $assignedUserIds)
            ->select('id', 'login')
            ->orderBy('login')
            ->get();

        $assignedAclIds = $aclRules->keys()->toArray();
        $allAcls = DB::table('acl')
            ->whereNotIn('id', $assignedAclIds)
            ->select('id', 'note')
            ->orderBy('id')
            ->get();

        return view('aro_groups.show', compact('group', 'aclRules', 'users', 'allUsers', 'allAcls'));
    }

    public function create()
    {
        abort_unless($this->can('new_all'), 403);
        $groups = AroGroup::orderBy('lft')->get();
        return view('aro_groups.create', compact('groups'));
    }

    public function store(Request $request)
    {
        abort_unless($this->can('new_all'), 403);

        $validated = $request->validate([
            'name'      => 'required|string|max:50',
            'parent_id' => 'required|integer|exists:aro_groups,id',
        ]);

        AroGroup::create([
            'name'      => $validated['name'],
            'parent_id' => $validated['parent_id'],
            'lft'       => 0,
            'rgt'       => 0,
            'value'     => strtolower(str_replace(' ', '_', $validated['name'])),
        ]);

        return redirect()->route('aro-groups.index')
            ->with('success', 'Skupina byla vytvořena.');
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);
        $group  = AroGroup::findOrFail($id);
        $groups = AroGroup::where('id', '!=', $id)->orderBy('lft')->get();
        return view('aro_groups.edit', compact('group', 'groups'));
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $group = AroGroup::findOrFail($id);

        $validated = $request->validate([
            'name'      => 'required|string|max:50',
            'parent_id' => 'required|integer|exists:aro_groups,id',
        ]);

        $group->update($validated);

        return redirect()->route('aro-groups.show', $id)
            ->with('success', 'Skupina byla upravena.');
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);

        $group = AroGroup::findOrFail($id);

        if ($group->children()->count() > 0) {
            return back()->with('error', 'Nelze smazat skupinu, která má podskupiny.');
        }

        if (DB::table('groups_aro_map')->where('group_id', $id)->count() > 0) {
            return back()->with('error', 'Nelze smazat skupinu, která má přiřazené uživatele.');
        }

        DB::table('aro_groups_map')->where('group_id', $id)->delete();
        $group->delete();

        return redirect()->route('aro-groups.index')
            ->with('success', 'Skupina byla smazána.');
    }

    public function addUser(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $exists = DB::table('groups_aro_map')
            ->where('group_id', $id)
            ->where('aro_id', $validated['user_id'])
            ->exists();

        if (!$exists) {
            DB::table('groups_aro_map')->insert([
                'group_id' => $id,
                'aro_id'   => $validated['user_id'],
            ]);
        }

        return redirect()->route('aro-groups.show', $id)
            ->with('success', 'Uživatel byl přidán do skupiny.');
    }

    public function removeUser(int $id, int $userId)
    {
        abort_unless($this->can('edit_all'), 403);

        DB::table('groups_aro_map')
            ->where('group_id', $id)
            ->where('aro_id', $userId)
            ->delete();

        return redirect()->route('aro-groups.show', $id)
            ->with('success', 'Uživatel byl odebrán ze skupiny.');
    }

    public function addAcl(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $validated = $request->validate([
            'acl_id' => 'required|integer|exists:acl,id',
        ]);

        $exists = DB::table('aro_groups_map')
            ->where('group_id', $id)
            ->where('acl_id', $validated['acl_id'])
            ->exists();

        if (!$exists) {
            DB::table('aro_groups_map')->insert([
                'group_id' => $id,
                'acl_id'   => $validated['acl_id'],
            ]);
        }

        return redirect()->route('aro-groups.show', $id)
            ->with('success', 'ACL pravidlo bylo přiřazeno skupině.');
    }

    public function removeAcl(int $id, int $aclId)
    {
        abort_unless($this->can('edit_all'), 403);

        DB::table('aro_groups_map')
            ->where('group_id', $id)
            ->where('acl_id', $aclId)
            ->delete();

        return redirect()->route('aro-groups.show', $id)
            ->with('success', 'ACL pravidlo bylo odebráno skupině.');
    }

    private function buildTree($groups, $parentId = null, $depth = 0): array
    {
        $tree = [];
        foreach ($groups as $group) {
            if ($group->parent_id == $parentId) {
                $tree[] = [
                    'group'    => $group,
                    'depth'    => $depth,
                    'children' => $this->buildTree($groups, $group->id, $depth + 1),
                ];
            }
        }
        return $tree;
    }
}
