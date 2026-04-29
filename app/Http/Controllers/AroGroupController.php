<?php

namespace App\Http\Controllers;

use App\Models\AroGroup;
use App\Services\AclService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AroGroupController extends Controller
{
    private const ACL_SECTION = 'Aro_groups_Controller';
    private const ACL_VALUE   = 'aro_group';

    public function __construct(private AclService $acl) {}

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index()
    {
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
        $this->acl->flushAllCache();

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
        $this->acl->flushAllCache();

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
            $this->acl->flushUserCache((int) $validated['user_id']);
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
        $this->acl->flushUserCache($userId);

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
            $this->acl->flushAllCache();
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
        $this->acl->flushAllCache();

        return redirect()->route('aro-groups.show', $id)
            ->with('success', 'ACL pravidlo bylo odebráno skupině.');
    }

    private function aclFormData(): array
    {
        $actions = ['view_all', 'edit_all', 'new_all', 'delete_all',
                    'view_own', 'edit_own', 'new_own', 'delete_own'];

        $axo = DB::table('axo')
            ->orderBy('section_value')
            ->orderBy('name')
            ->get(['id', 'section_value', 'value', 'name']);

        $groups = AroGroup::orderBy('lft')->get(['id', 'name']);

        return compact('actions', 'axo', 'groups');
    }

    public function aclCreate()
    {
        abort_unless($this->can('new_all'), 403);
        return view('aro_groups.acl_form', $this->aclFormData() + ['acl' => null, 'selected' => []]);
    }

    public function aclStore(Request $request)
    {
        abort_unless($this->can('new_all'), 403);

        $validated = $request->validate([
            'note'        => 'required|string|max:255',
            'actions'     => 'required|array|min:1',
            'actions.*'   => 'string',
            'axo_ids'     => 'required|array|min:1',
            'axo_ids.*'   => 'integer|exists:axo,id',
            'group_ids'   => 'nullable|array',
            'group_ids.*' => 'integer|exists:aro_groups,id',
        ]);

        $aclId = DB::table('acl')->insertGetId(['note' => $validated['note']]);

        foreach ($validated['actions'] as $action) {
            DB::table('aco_map')->insert(['acl_id' => $aclId, 'value' => $action]);
        }

        $axoRows = DB::table('axo')->whereIn('id', $validated['axo_ids'])->get(['section_value', 'value']);
        foreach ($axoRows as $axo) {
            DB::table('axo_map')->insert([
                'acl_id'        => $aclId,
                'section_value' => $axo->section_value,
                'value'         => $axo->value,
            ]);
        }

        foreach ($validated['group_ids'] ?? [] as $groupId) {
            DB::table('aro_groups_map')->insert(['acl_id' => $aclId, 'group_id' => $groupId]);
        }

        $this->acl->flushAllCache();

        return redirect()->route('aro-groups.index')
            ->with('success', 'ACL pravidlo bylo vytvořeno.');
    }

    public function aclEdit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $acl = DB::table('acl')->where('id', $id)->first();
        abort_if($acl === null, 404);

        $selected = [
            'actions'   => DB::table('aco_map')->where('acl_id', $id)->pluck('value')->toArray(),
            'axo_ids'   => DB::table('axo')
                ->join('axo_map', function ($j) use ($id) {
                    $j->on('axo.section_value', '=', 'axo_map.section_value')
                      ->on('axo.value', '=', 'axo_map.value')
                      ->where('axo_map.acl_id', $id);
                })
                ->pluck('axo.id')->toArray(),
            'group_ids' => DB::table('aro_groups_map')->where('acl_id', $id)->pluck('group_id')->toArray(),
        ];

        return view('aro_groups.acl_form', $this->aclFormData() + compact('acl', 'selected'));
    }

    public function aclUpdate(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $validated = $request->validate([
            'note'        => 'required|string|max:255',
            'actions'     => 'required|array|min:1',
            'actions.*'   => 'string',
            'axo_ids'     => 'required|array|min:1',
            'axo_ids.*'   => 'integer|exists:axo,id',
            'group_ids'   => 'nullable|array',
            'group_ids.*' => 'integer|exists:aro_groups,id',
        ]);

        DB::table('acl')->where('id', $id)->update(['note' => $validated['note']]);

        DB::table('aco_map')->where('acl_id', $id)->delete();
        foreach ($validated['actions'] as $action) {
            DB::table('aco_map')->insert(['acl_id' => $id, 'value' => $action]);
        }

        DB::table('axo_map')->where('acl_id', $id)->delete();
        $axoRows = DB::table('axo')->whereIn('id', $validated['axo_ids'])->get(['section_value', 'value']);
        foreach ($axoRows as $axo) {
            DB::table('axo_map')->insert([
                'acl_id'        => $id,
                'section_value' => $axo->section_value,
                'value'         => $axo->value,
            ]);
        }

        DB::table('aro_groups_map')->where('acl_id', $id)->delete();
        foreach ($validated['group_ids'] ?? [] as $groupId) {
            DB::table('aro_groups_map')->insert(['acl_id' => $id, 'group_id' => $groupId]);
        }

        $this->acl->flushAllCache();

        return redirect()->route('aro-groups.index')
            ->with('success', 'ACL pravidlo bylo upraveno.');
    }

    public function aclDestroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);

        DB::table('aco_map')->where('acl_id', $id)->delete();
        DB::table('axo_map')->where('acl_id', $id)->delete();
        DB::table('aro_groups_map')->where('acl_id', $id)->delete();
        DB::table('acl')->where('id', $id)->delete();
        $this->acl->flushAllCache();

        return redirect()->route('aro-groups.index')
            ->with('success', 'ACL pravidlo bylo smazáno.');
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
